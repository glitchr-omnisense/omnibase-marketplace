<?php

namespace Base\Marketplace\Twig;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Intl\Currencies;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * What an invoice's page prints its amounts with: an integer in the
 * currency's smallest unit, written as the seller's language writes money.
 *
 *   {{ invoice.total|invoice_money(invoice.currency) }}            1 234,56 €
 *   {{ line.rate|invoice_rate }}                                   20 % / 5,5 %
 *   {{ line|invoice_quantity }}                                    2 / 4 h 25 (an hours line from its minutes)
 *
 * And an order's invoices and credit notes, oldest first, for its page:
 *
 *   {% for invoice in marketplace_order_invoices(order) %}...{% endfor %}
 */
final class InvoiceTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ?EntityManagerInterface $entityManager = null)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('marketplace_order_invoices', $this->ofOrder(...))];
    }

    /** @return Invoice[] */
    public function ofOrder(Order $order): array
    {
        if (null === $this->entityManager || null === $order->getId()) {
            return [];
        }

        return $this->entityManager->getRepository(Invoice::class)->ofOrder($order);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('invoice_money', $this->money(...)),
            new TwigFilter('invoice_rate', $this->rate(...)),
            new TwigFilter('invoice_quantity', $this->quantity(...)),
        ];
    }

    /**
     * A line's quantity as a person reads it: an hours line that gives its
     * minutes (unit HUR, as FacturX counts it) in hours and minutes - 265
     * minutes are "4 h 25", not the 4.4167 the Factur-X carries -, any
     * other as it is.
     *
     * @param array<string, mixed> $line one of Invoice::getLines()
     */
    public function quantity(array $line): string
    {
        if ('HUR' === ($line['unit'] ?? null) && isset($line['minutes'])) {
            $minutes = (int) $line['minutes'];
            $sign = $minutes < 0 ? '-' : '';
            $minutes = abs($minutes);

            return $sign.intdiv($minutes, 60).' h'.(0 === $minutes % 60 ? '' : sprintf(' %02d', $minutes % 60));
        }

        return (string) ($line['quantity'] ?? '');
    }

    public function money(int|float|null $amount, string $currency, string $locale = 'fr_FR'): string
    {
        $value = ((float) $amount) / 10 ** Currencies::getFractionDigits($currency);
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($value, $currency);
    }

    public function rate(int|float|null $rate, string $locale = 'fr_FR'): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);

        return $formatter->format((float) $rate).' %';
    }
}
