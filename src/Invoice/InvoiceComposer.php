<?php

namespace Base\Marketplace\Invoice;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Model\VatCustomerInterface;
use Base\Marketplace\Pricing\ExportExemption;
use Base\Marketplace\Pricing\ReverseCharge;
use Base\Marketplace\Pricing\StoreVatRegime;
use Base\Marketplace\Service\Pricing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an invoice says, written from an order the day it is issued: the
 * seller (marketplace.invoice.seller, the store's VAT number over it), the
 * buyer (the customer, the order's billing and delivery addresses), the
 * lines, the totals, the mentions - copied, so that the invoice no longer
 * depends on them.
 *
 * The amounts are the order's, its VAT included: each line's price before
 * VAT and its VAT are read, not computed again. Only the order's own charges
 * (shipping, service, other taxes) and its order-level discount carry no VAT
 * of their own in the order: they are amounts the buyer paid, VAT included,
 * and each is split between the VAT rates of the lines in proportion of
 * their bases - an accessory follows the rate of what it accompanies - so
 * that the invoice's total is exactly what the order cost.
 */
final class InvoiceComposer
{
    /** The natures of operations an invoice states (CGI ann. II art. 242 nonies A). */
    public const OPERATIONS = ['goods', 'services', 'mixed'];

    /** @param array<string, mixed> $seller marketplace.invoice.seller */
    public function __construct(
        private readonly Pricing $pricing,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire('%marketplace.invoice.seller%')] private readonly array $seller = [],
        #[Autowire('%marketplace.invoice.operation%')] private readonly string $operation = 'goods',
        #[Autowire('%marketplace.invoice.vat_on_debits%')] private readonly bool $vatOnDebits = false,
        #[Autowire('%marketplace.invoice.late_penalties%')] private readonly ?string $latePenalties = null,
        #[Autowire('%marketplace.invoice.discount%')] private readonly ?string $discount = null,
        #[Autowire('%marketplace.invoice.mentions%')] private readonly array $mentions = [],
    ) {
    }

    /** @return array<string, mixed> the seller that day */
    public function seller(Order $order): array
    {
        $seller = $this->sellerFor($order->getStore(), $order->getManager()?->getCompanyName());
        $seller['store'] = (string) $order->getStore();

        return $seller;
    }

    /**
     * The seller that day without an order (an invoice of hours, of a
     * subscription): marketplace.invoice.seller, the store's VAT number over
     * it, its name - when the setting gives none - $name, else the store's.
     *
     * @return array<string, mixed>
     */
    public function sellerFor(?Store $store = null, ?string $name = null): array
    {
        $seller = $this->seller + ['name' => '', 'street' => [], 'postcode' => '', 'city' => '', 'country' => 'FR'];
        $seller['street'] = array_values(array_filter((array) $seller['street']));
        $seller['vat_number'] = $store?->getVatNumber() ?? ($seller['vat_number'] ?? null);
        $seller['store'] = null !== $store ? (string) $store : null;
        if ('' === trim((string) $seller['name'])) {
            $seller['name'] = (string) (self::blank($name) ?? $store);
        }

        return $seller;
    }

    /** @return array<string, mixed> the buyer that day */
    public function buyer(Order $order): array
    {
        return $this->buyerOf($order->getCustomer(), $order->getBillingAddress() ?? $order->getShippingAddress(), $order->getShippingAddress());
    }

    /**
     * The buyer that day without an order: a customer (its company name,
     * SIREN, SIRET, VAT number, e-mail - read where it has them), its billing
     * address, and the delivery address when it is another.
     *
     * @return array<string, mixed>
     */
    public function buyerOf(?object $customer, ?object $billing = null, ?object $delivery = null): array
    {
        $company = self::call($customer, 'getCompanyName');

        $buyer = [
            'name' => $company ?? self::call($billing, 'getName') ?? self::person($customer),
            'company' => null !== $company,
            'email' => self::call($customer, 'getEmail'),
            'address' => self::address($billing),
            'siren' => self::call($customer, 'getSiren'),
            'siret' => self::call($customer, 'getSiret'),
            'vat_number' => $customer instanceof VatCustomerInterface ? self::blank($customer->getVatNumber()) : self::call($customer, 'getVatNumber'),
            'delivery' => null,
        ];
        $buyer['business'] = $buyer['company'] || null !== $buyer['siren'] || null !== $buyer['siret'] || null !== $buyer['vat_number'];
        // The delivery address, when it is not the buyer's: a mention required since 2026-09-01.
        if ($delivery && $billing !== $delivery && self::address($delivery) !== $buyer['address']) {
            $buyer['delivery'] = ['name' => self::call($delivery, 'getName')] + self::address($delivery);
        }

        return $buyer;
    }

    /**
     * The order's lines, and its charges and discount split by VAT rate.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function amounts(Order $order): array
    {
        $exemption = self::exemption($order->getVatExemption());
        $lines = [];
        $bases = [];
        foreach ($order->getItems() as $i => $item) {
            $rate = $exemption ? 0.0 : $this->rate($order, $item);
            $key = self::key($rate);
            $ht = $item->getSalePrice();
            $vat = $item->getVatCharge();
            $lines[] = [
                'id' => (string) (\count($lines) + 1),
                'label' => self::label($item),
                'reference' => self::call($item->getProduct(), 'getReference'),
                'quantity' => (int) $item->getQuantity(),
                'unit_price' => $ht / max(1, (int) $item->getQuantity()),
                'gross_unit_price' => $item->getUnitPrice(),
                'discount' => $item->getDiscountCharge(),
                'rate' => $rate,
                'category' => $exemption['category'] ?? ($rate > 0 ? 'S' : 'Z'),
                'total' => $ht,
                'vat' => $vat,
                'total_with_vat' => $ht + $vat,
            ];
            $bases[$key] ??= ['rate' => $rate, 'category' => $exemption['category'] ?? ($rate > 0 ? 'S' : 'Z'), 'lines' => 0, 'lines_vat' => 0, 'charges' => 0, 'charges_vat' => 0, 'allowances' => 0, 'allowances_vat' => 0];
            $bases[$key]['lines'] += $ht;
            $bases[$key]['lines_vat'] += $vat;
        }
        if ([] === $bases) {
            $bases['0'] = ['rate' => 0.0, 'category' => $exemption['category'] ?? 'Z', 'lines' => 0, 'lines_vat' => 0, 'charges' => 0, 'charges_vat' => 0, 'allowances' => 0, 'allowances_vat' => 0];
        }

        $charges = [];
        foreach (['shipping' => $order->getShippingCharge(), 'service' => $order->getServiceCharge(), 'taxes' => $order->getAdditionalTaxCharge()] as $kind => $amount) {
            foreach ($this->split($amount, $bases) as $key => [$ht, $vat]) {
                $charges[] = ['kind' => $kind, 'rate' => $bases[$key]['rate'], 'category' => $bases[$key]['category'], 'total' => $ht, 'vat' => $vat, 'total_with_vat' => $ht + $vat];
                $bases[$key]['charges'] += $ht;
                $bases[$key]['charges_vat'] += $vat;
            }
        }
        $allowances = [];
        foreach ($this->split($order->getDiscountCharge(), $bases) as $key => [$ht, $vat]) {
            $allowances[] = ['kind' => 'discount', 'rate' => $bases[$key]['rate'], 'category' => $bases[$key]['category'], 'total' => $ht, 'vat' => $vat, 'total_with_vat' => $ht + $vat];
            $bases[$key]['allowances'] += $ht;
            $bases[$key]['allowances_vat'] += $vat;
        }

        $vat = [];
        foreach ($bases as $base) {
            $vat[] = [
                'rate' => $base['rate'],
                'category' => $base['category'],
                'base' => $base['lines'] + $base['charges'] - $base['allowances'],
                'vat' => $base['lines_vat'] + $base['charges_vat'] - $base['allowances_vat'],
            ];
        }
        usort($vat, fn (array $a, array $b) => $a['rate'] <=> $b['rate']);

        $totalHt = array_sum(array_column($vat, 'base'));
        $totalVat = array_sum(array_column($vat, 'vat'));
        $total = $totalHt + $totalVat;
        $paid = min($order->getTotalPaid(), $total);

        return ['lines' => $lines, 'totals' => [
            'lines' => array_sum(array_column($lines, 'total')),
            'charges' => $charges,
            'allowances' => $allowances,
            'charges_total' => array_sum(array_column($charges, 'total')),
            'allowances_total' => array_sum(array_column($allowances, 'total')),
            'vat_breakdown' => $vat,
            'total_without_vat' => $totalHt,
            'vat' => $totalVat,
            'total' => $total,
            'paid' => $paid,
            'due' => $total - $paid,
            'exemption' => $exemption,
        ]];
    }

    /**
     * The mentions the invoice prints, in the seller's language: the VAT
     * exemption, the nature of the operations, the option for VAT on
     * debits, the payment (made, or its date), the discount for early
     * payment and - to a business - the penalties for late payment and the
     * flat indemnity, then the seller's own.
     *
     * @param array<string, mixed> $buyer
     * @param array<string, mixed> $totals
     *
     * @return list<string>
     */
    public function mentions(Order $order, array $buyer, array $totals, \DateTimeImmutable $issuedAt, ?\DateTimeImmutable $dueAt, ?Invoice $credited = null, ?string $reason = null): array
    {
        return $this->mentionsFor($buyer, ['exemption' => self::exemption($order->getVatExemption())] + $totals, $dueAt,
            paidAt: null !== $order->getPaidAt() ? \DateTimeImmutable::createFromInterface($order->getPaidAt()) : null,
            credited: $credited, reason: $reason);
    }

    /**
     * The same mentions, without an order: the VAT exemption read from the
     * totals (their "exemption", as amounts() writes it), the nature of the
     * operations given ($operation: goods, services, mixed; else
     * marketplace.invoice.operation), the invoice's own mentions after it (a
     * period of services), the payment made ($paidAt, the totals' due nothing)
     * or its date ($dueAt). A credit note ($credited) names the invoice it
     * cancels, then gives its reason - a reason that already names it, as
     * "Avoir annulant la facture F-2026-000042 du 02/11/2026. Taux erroné.",
     * is written as it is, once.
     *
     * @param array<string, mixed> $buyer
     * @param array<string, mixed> $totals
     * @param list<string>         $own
     *
     * @return list<string>
     */
    public function mentionsFor(array $buyer, array $totals, ?\DateTimeImmutable $dueAt = null, ?string $operation = null, array $own = [], ?\DateTimeImmutable $paidAt = null, ?Invoice $credited = null, ?string $reason = null): array
    {
        $locale = $this->locale();
        $mentions = [];
        if (null !== $credited) {
            $mention = $this->creditMention($credited);
            $reason = null !== $reason ? trim($reason) : '';
            if (!str_starts_with($reason, $mention)) {
                $mentions[] = $mention;
            }
            if ('' !== $reason) {
                $mentions[] = $reason;
            }
        }
        if (null !== ($totals['exemption']['reason'] ?? null)) {
            $mentions[] = (string) $totals['exemption']['reason'];
        }
        $operation ??= $this->operation;
        $mentions[] = $this->trans('@marketplace.invoice.mention.operation.'.(\in_array($operation, self::OPERATIONS, true) ? $operation : 'goods'), [], $locale);
        foreach ($own as $mention) {
            $mentions[] = $this->trans((string) $mention, [], $locale);
        }
        if ($this->vatOnDebits) {
            $mentions[] = $this->trans('@marketplace.invoice.mention.vat_on_debits', [], $locale);
        }
        if (null === $credited) {
            if (($totals['due'] ?? 0) <= 0 && null !== $paidAt) {
                $mentions[] = $this->trans('@marketplace.invoice.mention.paid', ['{date}' => $paidAt->format('d/m/Y')], $locale);
            } elseif (null !== $dueAt) {
                $mentions[] = $this->trans('@marketplace.invoice.mention.due', ['{date}' => $dueAt->format('d/m/Y')], $locale);
            }
            if (null !== $this->discount && '' !== $this->discount) {
                $mentions[] = $this->trans($this->discount, [], $locale);
            }
            if ($buyer['business'] ?? false) {
                if (null !== $this->latePenalties && '' !== $this->latePenalties) {
                    $mentions[] = $this->trans($this->latePenalties, [], $locale);
                }
            }
        }
        foreach ($this->mentions as $mention) {
            $mentions[] = $this->trans((string) $mention, [], $locale);
        }

        return array_values(array_filter($mentions, fn ($m) => '' !== trim((string) $m)));
    }

    /** "Avoir annulant la facture F-2026-000042 du 02/11/2026.": what a credit note says of the invoice it cancels. */
    public function creditMention(Invoice $credited): string
    {
        return $this->trans('@marketplace.invoice.mention.credit', ['{number}' => $credited->getNumber(), '{date}' => $credited->getIssuedAt()->format('d/m/Y')], $this->locale());
    }

    /**
     * The nature of the operations an invoice stated (goods, services, mixed),
     * read back from its mentions; null when it states none this setting knows.
     */
    public function operationOf(Invoice $invoice): ?string
    {
        $locale = $this->locale();
        foreach (self::OPERATIONS as $operation) {
            if (\in_array($this->trans('@marketplace.invoice.mention.operation.'.$operation, [], $locale), $invoice->getMentions(), true)) {
                return $operation;
            }
        }

        return null;
    }

    /** An invoice is written in its seller's language: French for a French seller. */
    private function locale(): ?string
    {
        return 'FR' === strtoupper((string) (($this->seller['country'] ?? '') ?: 'FR')) ? 'fr' : null;
    }

    private function trans(string $text, array $parameters, ?string $locale): string
    {
        if (!str_starts_with($text, '@') || null === $this->translator) {
            return strtr($text, $parameters);
        }

        return $this->translator->trans($text, $parameters, null, $locale);
    }

    /** The VAT rate of a line, in percent: the shop's for its product, else what the order's amounts say. */
    private function rate(Order $order, OrderItem $item): float
    {
        if (0 === $item->getVatCharge()) {
            return 0.0;
        }
        if ($product = $item->getProduct()) {
            try {
                $rate = $this->pricing->vatRateFor($product, $order->getRegion(), $order);
                if ($rate > 0 && abs($item->getSalePrice() * $rate - $item->getVatCharge()) <= 1) {
                    return round($rate * 100, 3);
                }
            } catch (\Throwable) {
            }
        }

        return $item->getSalePrice() > 0 ? round($item->getVatCharge() / $item->getSalePrice() * 100, 1) : 0.0;
    }

    /**
     * An amount paid VAT included, split between the rates in proportion of their lines' bases.
     *
     * @param array<string, array<string, mixed>> $bases
     *
     * @return array<string, array{int, int}> by rate: before VAT, VAT
     */
    private function split(int $amount, array $bases): array
    {
        if ($amount <= 0) {
            return [];
        }
        $sum = array_sum(array_column($bases, 'lines'));
        $keys = array_keys($bases);
        $parts = [];
        $left = $amount;
        foreach ($keys as $n => $key) {
            $part = $n === \count($keys) - 1 ? $left : ($sum > 0 ? (int) round($amount * $bases[$key]['lines'] / $sum) : 0);
            $part = min($part, $left);
            $left -= $part;
            if ($part <= 0) {
                continue;
            }
            $rate = (float) $bases[$key]['rate'];
            $ht = (int) round($part / (1 + $rate / 100));
            $parts[$key] = [$ht, $part - $ht];
        }

        return $parts;
    }

    private static function key(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.');
    }

    /**
     * The category of an exempt order's VAT (EN 16931, UNTDID 5305) and its reason, from the mention Pricing wrote.
     *
     * @return array{category: string, reason: string, code: ?string}|null
     */
    public static function exemption(?string $mention): ?array
    {
        if (null === $mention) {
            return null;
        }
        if (str_starts_with($mention, StoreVatRegime::MENTION)) {
            return ['category' => 'E', 'reason' => $mention, 'code' => 'VATEX-FR-FRANCHISE'];
        }
        if (str_starts_with($mention, ReverseCharge::MENTION) || str_starts_with($mention, ReverseCharge::MENTION_EU)) {
            return ['category' => 'AE', 'reason' => $mention, 'code' => 'VATEX-EU-AE'];
        }
        if (str_starts_with($mention, ExportExemption::MENTION) || str_starts_with($mention, ExportExemption::MENTION_EU)) {
            return ['category' => 'G', 'reason' => $mention, 'code' => 'VATEX-EU-G'];
        }

        return ['category' => 'E', 'reason' => $mention, 'code' => null];
    }

    private static function label(OrderItem $item): string
    {
        $product = $item->getProduct();
        $label = $product ? (string) (self::call($product, 'getTitle') ?? $product) : (string) $item;
        $options = $item->getOptionsLabel();

        return '' !== $options ? $label.' ('.$options.')' : $label;
    }

    /** @return array{street: list<string>, postcode: string, city: string, country: string} */
    private static function address(?object $address): array
    {
        return [
            'street' => array_values(array_filter([self::call($address, 'getStreetAddress'), self::call($address, 'getAdditional')])),
            'postcode' => (string) self::call($address, 'getZipCode'),
            'city' => (string) self::call($address, 'getCity'),
            'country' => strtoupper((string) (self::call($address, 'getCountry') ?? '')),
        ];
    }

    private static function person(?object $customer): ?string
    {
        if (null === $customer) {
            return null;
        }
        $name = trim(((string) self::call($customer, 'getFirstname')).' '.((string) self::call($customer, 'getLastname')));

        return '' !== $name ? $name : (self::call($customer, 'getUsername') ?? $customer->getEmail());
    }

    private static function blank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private static function call(?object $object, string $method): ?string
    {
        if (null === $object || !method_exists($object, $method)) {
            return null;
        }
        $value = $object->$method();

        return null === $value || '' === trim((string) $value) ? null : trim((string) $value);
    }
}
