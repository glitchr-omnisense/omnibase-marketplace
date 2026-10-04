<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Event\PaymentPreparingEvent;
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
use Omnitrade\Request\Subscribe;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        private readonly ?EventDispatcherInterface $dispatcher = null,
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
            $event = $this->prepare($order, $transaction, $method);
            $payment = $this->payment($order, $transaction, $method, $event);
            $paid = $event->isSubscription()
                ? $this->gateway->execute(new Subscribe($payment, $event->interval, $event->intervalCount, $event->price, $event->trialDays, $event->customer))->getTransaction()
                : $this->gateway->purchase($payment);
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

    /**
     * How the order goes to the provider, as the application's listeners
     * have it (Event\PaymentPreparingEvent): an order of one recurring plan
     * is a subscription of that plan's interval unless they say otherwise.
     */
    public function prepare(Order $order, Transaction $transaction, PaymentMethod $method): PaymentPreparingEvent
    {
        $event = new PaymentPreparingEvent($order, $transaction, $method, $this->gatewayName, ['order' => (string) ($order->getReference() ?? $order->getId()), 'method' => (string) $method->getSlug()]);
        $items = $order->getItems();
        if (1 === \count($items) && class_exists(Subscribe::class) && $this->gateway->supports(Subscribe::class)) {
            $item = $items->first();
            $product = $item->getProduct();
            if ($product && $product->isPlan() && 1 === (int) $item->getQuantity() && $product->getPlanTerms()->isRecurring()) {
                $event->interval = $product->getPlanTerms()->interval;
                $event->description = (string) $product;
            }
        }
        $this->dispatcher?->dispatch($event);

        return $event;
    }

    /** What the provider is asked for: the order, as omnitrade describes a payment. */
    public function payment(Order $order, Transaction $transaction, PaymentMethod $method, ?PaymentPreparingEvent $event = null): Payment
    {
        $event ??= $this->prepare($order, $transaction, $method);
        // A destination and its fee only where glitchr/omnitrade knows them (1.x with Connect).
        $connect = null !== $event->destination && property_exists(Payment::class, 'destination')
            ? ['destination' => $event->destination, 'applicationFee' => null !== $event->applicationFee && $event->applicationFee > 0 ? Money::of($event->applicationFee, strtoupper((string) $order->getCurrency())) : null]
            : [];
        $currency = strtoupper((string) $order->getCurrency());
        $return = fn (array $query) => $this->urls->generate('marketplace_payment_return', ['order' => $order->getId(), 'gateway' => $this->gatewayName] + $query, UrlGeneratorInterface::ABSOLUTE_URL);
        $customer = $order->getCustomer();
        $address = $order->getShippingAddress();

        return new Payment(
            Money::of((int) $order->getNetPrice(), $currency),
            (string) ($order->getReference() ?? '#'.$order->getId()),
            $event->description ?? (string) ($order->getStore() ?? $order->getReference()),
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
            ...[
                'returnUrl' => $return([]),
                'cancelUrl' => $return(['cancel' => 1]),
                'idempotencyKey' => sprintf('%s-%s', $order->getReference() ?? $order->getId(), $transaction->getId() ?? spl_object_id($transaction)),
                'method' => $method->getGatewayParameters()['method'] ?? null,
                'notice' => $order->getVatExemption(),
                'metadata' => $event->metadata,
                'locale' => $event->locale,
            ] + $connect,
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
            // A subscription's session: the provider's subscription and customer, for Service\Subscriptions.
            'subscription' => \is_string($paid->raw['subscription'] ?? null) ? $paid->raw['subscription'] : null,
            'customer' => \is_string($paid->raw['customer'] ?? null) ? $paid->raw['customer'] : null,
        ] + $transaction->getDetails(), static fn ($v) => null !== $v && '' !== $v));
    }
}
