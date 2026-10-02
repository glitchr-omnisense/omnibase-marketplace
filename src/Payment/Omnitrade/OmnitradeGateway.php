<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Payment\PaymentGatewayInterface;
use Base\Marketplace\Payment\PaymentResult;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction as OmnitradeTransaction;
use Omnitrade\Request\Purchase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A payment method that names a glitchr/omnitrade gateway: the shop's
 * PaymentMethod is a proxy, its `gatewayFactory` the name of a gateway
 * configured under omnitrade.gateways (stripe, paypal, a Shopify shop...),
 * and this bridge takes the order there.
 *
 *     omnitrade:
 *         gateways:
 *             card: { factory: stripe, options: { api_key: '%env(STRIPE_API_KEY)%', webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%' } }
 *
 *     a PaymentMethod "Carte bancaire", gatewayFactory: card
 *
 * One instance per omnitrade gateway, built by OmnitradeGateways and found
 * by PaymentGatewayRegistry after the application's own gateways. The
 * provider's reference for the payment (a Checkout session, a PayPal order)
 * is kept on the Transaction (webhook), where PaymentController's return
 * page and webhook find it again.
 */
final class OmnitradeGateway implements PaymentGatewayInterface
{
    /** @param array<string, mixed> $options the gateway's options as it runs on: configured, and typed in the back office over them */
    public function __construct(
        private readonly string $gatewayName,
        private readonly GatewayInterface $gateway,
        private readonly UrlGeneratorInterface $urls,
        private readonly array $options = [],
    ) {
    }

    /** One of the options the gateway runs on (api_key, webhook_secret...), null when unset or empty. */
    public function option(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /** Every bridge answers to this; the registry keys them by their omnitrade gateway's name (getName()). */
    public static function name(): string
    {
        return 'omnitrade';
    }

    /** The omnitrade gateway's name: what a PaymentMethod's gatewayFactory holds. */
    public function getName(): string
    {
        return $this->gatewayName;
    }

    /** The provider behind it: stripe, paypal, shopify, woocommerce. */
    public function provider(): string
    {
        return $this->gateway->getName();
    }

    public function gateway(): GatewayInterface
    {
        return $this->gateway;
    }

    public function supports(Order $order, PaymentMethod $method): bool
    {
        return $this->gateway->supports(Purchase::class);
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        try {
            $paid = $this->gateway->purchase($this->payment($order, $transaction, $method));
        } catch (OmnitradeException $e) {
            return PaymentResult::refused('payment.unavailable', ['{reason}' => $e->getMessage()]);
        }
        $this->record($transaction, $paid);

        return match ($paid->status) {
            Status::PAID => PaymentResult::paid(),
            Status::REFUSED, Status::CANCELLED, Status::EXPIRED => PaymentResult::refused('payment.refused', ['{reason}' => (string) $paid->message]),
            default => null !== $paid->redirectUrl ? PaymentResult::redirect($paid->redirectUrl) : PaymentResult::pending(),
        };
    }

    /** Where the provider's payment stands now. */
    public function fetch(Transaction $transaction): OmnitradeTransaction
    {
        $paid = $this->gateway->fetch(self::reference($transaction));
        $this->record($transaction, $paid);

        return $paid;
    }

    /**
     * A webhook, checked and read.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws \Omnitrade\Exception\InvalidNotificationException when it was not the provider's
     */
    public function notify(string $body, array $headers): Notification
    {
        return $this->gateway->notify($body, $headers);
    }

    /**
     * Pays $amount (minor units) back on what the transaction was paid with
     * and returns the provider's refund id. The idempotency key makes the
     * same refund asked twice happen once.
     *
     * @throws OmnitradeException when the provider knows no payment for it, or refuses
     */
    public function refund(Transaction $transaction, int $amount, string $currency, string $idempotencyKey, ?string $reason = null): string
    {
        return $this->gateway->refund(self::reference($transaction), Money::of($amount, $currency), $idempotencyKey, $reason)->reference;
    }

    /** The provider's reference the transaction was paid under: kept as its webhook, else in its details (an older payment's stripe_session too). */
    public static function reference(Transaction $transaction): string
    {
        $details = $transaction->getDetails();

        return (string) ($transaction->getWebhook() ?: ($details['reference'] ?? $details['stripe_session'] ?? ''));
    }

    /** What the provider is asked for: the order, as omnitrade describes a payment. */
    public function payment(Order $order, Transaction $transaction, PaymentMethod $method): Payment
    {
        $currency = strtoupper((string) $order->getCurrency());
        $return = fn (array $query) => $this->urls->generate('marketplace_payment_return', ['order' => $order->getId(), 'gateway' => $this->gatewayName] + $query, UrlGeneratorInterface::ABSOLUTE_URL);
        $customer = $order->getCustomer();
        $address = $order->getShippingAddress();

        return new Payment(
            Money::of((int) $order->getNetPrice(), $currency),
            (string) ($order->getReference() ?? '#'.$order->getId()),
            (string) ($order->getStore() ?? $order->getReference()),
            new Customer(
                $customer?->getEmail(),
                $address?->getName() ?: ($customer ? (string) $customer : null),
                null,
                $address?->getPhone(),
                array_values(array_filter([$address?->getStreetAddress(), $address?->getAffix()])),
                $address?->getZipCode(),
                $address?->getCity(),
                $address?->getCountry(),
            ),
            $this->lines($order, $currency),
            returnUrl: $return([]),
            cancelUrl: $return(['cancel' => 1]),
            idempotencyKey: sprintf('%s-%s', $order->getReference() ?? $order->getId(), $transaction->getId() ?? spl_object_id($transaction)),
            method: $method->getGatewayParameters()['method'] ?? null,
            notice: $order->getVatExemption(),
            metadata: ['order' => (string) ($order->getReference() ?? $order->getId()), 'method' => (string) $method->getSlug()],
        );
    }

    /**
     * The order's lines when they add up to its net price, in its currency;
     * else one line of the net price: VAT, shipping, fees and discounts live
     * on the order, not on its lines, and a provider charges the lines.
     *
     * @return list<Line>
     */
    private function lines(Order $order, string $currency): array
    {
        $total = (int) $order->getNetPrice();
        $lines = [];
        $sum = 0;
        foreach ($order->getItems() as $item) {
            if (strtoupper((string) $item->getCurrency()) !== $currency) {
                $lines = null;
                break;
            }
            $product = $item->getProduct();
            $lines[] = new Line((string) ($product ?? $item), Money::of((int) $item->getUnitPrice(), $currency), (int) $item->getQuantity(), $product?->getSku(), physical: (bool) $product?->isShippable());
            $sum += (int) $item->getUnitPrice() * (int) $item->getQuantity();
        }
        if (null !== $lines && [] !== $lines && $sum === $total) {
            return $lines;
        }

        return [new Line((string) ($order->getReference() ?? '#'.$order->getId()), Money::of($total, $currency), 1, physical: false)];
    }

    /** The provider's reference and state, kept on the transaction. */
    private function record(Transaction $transaction, OmnitradeTransaction $paid): void
    {
        if ('' !== $paid->reference) {
            $transaction->setWebhook($paid->reference);
        }
        $transaction->setDetails(array_filter([
            'gateway' => $this->gatewayName,
            'provider' => $paid->provider,
            'reference' => $paid->reference,
            'status' => $paid->status->value,
            'method' => $paid->method,
            'message' => $paid->message,
        ] + $transaction->getDetails(), static fn ($v) => null !== $v && '' !== $v));
    }
}
