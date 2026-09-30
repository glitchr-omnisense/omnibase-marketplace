<?php

namespace Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Service\SettingBagInterface;
use Base\Marketplace\Payment\Stripe\CheckoutGateway;
use Omnipay\Common\GatewayInterface;
use Omnipay\Common\Http\Client as OmnipayClient;
use Omnipay\Omnipay;
use Omnipay\Stripe\PaymentIntentsGateway;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Card payment through Stripe Checkout, the hosted payment page: the member
 * is sent to Stripe, then back to marketplace_stripe_return; the webhook
 * (marketplace_stripe_webhook) confirms the order even if they never come back.
 *
 * Built on omnipay/stripe's Checkout gateway, as a shop site is on
 * Omnipay. Only registered when that package is installed.
 *
 * Settings, under marketplace.gateways.<method slug>:
 *   api_key         the secret key (sk_live_... / sk_test_...)
 *   webhook_secret  the signing secret of the webhook endpoint (whsec_...) -
 *                   optional: marketplace:stripe:webhook creates the endpoint and
 *                   stores its secret in the settings, read when this is empty
 *   webhook_url     optional, the endpoint's public address for that command
 *   currencies      optional, the currencies the method takes
 *   adaptive_pricing  optional, true to let Stripe offer the buyer's own
 *                   currency (Adaptive Pricing); off, the order's currency only
 *
 * The buyer's email is filled in on Stripe's page. Stripe charges what the
 * transaction expects - the order's net price, VAT, shipping, fees and
 * discounts included (lines()) -, and a paid session can be refunded.
 *
 * Omnipay talks to Stripe through the application's HTTP client (a PSR-18
 * bridge): the profiler sees the calls, the tests mock them.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ?SettingBagInterface $settings = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /** Where marketplace:stripe:webhook keeps the secret of the endpoint it created for $method. */
    public static function webhookSecretSetting(PaymentMethod $method): string
    {
        return 'marketplace.stripe.'.str_replace(['-', '.'], '_', (string) $method->getSlug()).'.webhook_secret';
    }

    /** The endpoint's signing secret: the gateway's webhook_secret, else the one the command stored; null when neither. */
    public static function webhookSecret(PaymentMethod $method, ?SettingBagInterface $settings): ?string
    {
        $secret = (string) ($method->getGatewayParameters()['webhook_secret'] ?? '');
        if ('' === $secret && $settings) {
            $secret = (string) ($settings->getScalar(self::webhookSecretSetting($method)) ?? '');
        }

        return '' === $secret ? null : $secret;
    }

    public static function name(): string
    {
        return 'stripe';
    }

    public function supports(Order $order, PaymentMethod $method): bool
    {
        // Without a key the method cannot take anything: keep it off checkout.
        return '' !== $this->apiKey($method);
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        $lines = $this->lines($order);

        $return = fn (array $query) => $this->urls->generate('marketplace_stripe_return', ['order' => $order->getId()] + $query, UrlGeneratorInterface::ABSOLUTE_URL);
        $data = $this->gateway($method)->purchase([
            'mode' => 'payment',
            'line_items' => $lines,
            'customerEmail' => $order->getCustomer()?->getEmail(),
            // An order sold without VAT says why, on the page and the receipt.
            'vatMention' => $order->getVatExemption(),
            'adaptivePricing' => (bool) ($method->getGatewayParameters()['adaptive_pricing'] ?? false),
            // Stripe fills {CHECKOUT_SESSION_ID} in; the braces must survive URL encoding.
            'success_url' => str_replace('SESSION_ID_PLACEHOLDER', '{CHECKOUT_SESSION_ID}', $return(['session' => 'SESSION_ID_PLACEHOLDER'])),
            'cancel_url' => $return(['cancel' => 1]),
        ])->send()->getData();

        if (empty($data['id']) || empty($data['url'])) {
            return PaymentResult::refused('stripe.unavailable', ['{reason}' => (string) ($data['error']['message'] ?? '')]);
        }

        // The session id is how the return page and the webhook find this transaction again.
        $transaction->setWebhook($data['id']);
        $transaction->setDetails(['stripe_session' => $data['id']]);

        return PaymentResult::redirect($data['url']);
    }

    /**
     * What Stripe is asked to charge: the order's lines as they are when they
     * add up to its net price, in its currency; else one line of the net
     * price. Stripe Checkout takes no negative line (a discount), and VAT,
     * shipping and fees live on the order, not on its lines: charged line by
     * line, Stripe took less than the transaction expected (Checkout sets its
     * amount to Order::getNetPrice()). A line in another currency than the
     * order's would be charged as if it were in the order's.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Order $order): array
    {
        $currency = strtolower((string) $order->getCurrency());
        $total = (int) $order->getNetPrice();

        $lines = [];
        $sum = 0;
        foreach ($order->getItems() as $item) {
            if (strtolower((string) $item->getCurrency()) !== $currency) {
                $lines = null;
                break;
            }
            $lines[] = [
                'quantity' => (int) $item->getQuantity(),
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $item->getUnitPrice(),
                    'product_data' => ['name' => (string) ($item->getProduct() ?? $item)],
                ],
            ];
            $sum += (int) $item->getUnitPrice() * (int) $item->getQuantity();
        }
        if (null !== $lines && [] !== $lines && $sum === $total) {
            return $lines;
        }

        return [[
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $total,
                'product_data' => ['name' => (string) ($order->getReference() ?? '#'.$order->getId())],
            ],
        ]];
    }

    /** The Checkout session as Stripe has it now (payment_status: paid, unpaid, no_payment_required). */
    public function session(PaymentMethod $method, string $sessionId): array
    {
        return $this->gateway($method)->fetchTransaction(['transactionReference' => $sessionId])->send()->getData();
    }

    /**
     * Whether a webhook payload was signed by Stripe with this method's
     * secret (Stripe-Signature: t=timestamp,v1=hmac), within five minutes.
     */
    public function verify(PaymentMethod $method, string $payload, string $header, int $tolerance = 300): bool
    {
        $secret = self::webhookSecret($method, $this->settings);
        if (null === $secret) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $key) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pays $amount (cents) back on what the Checkout session was paid with -
     * its payment intent's charge -, and returns Stripe's refund id (re_...).
     * The idempotency key makes the same refund asked twice happen once.
     *
     * @throws \RuntimeException when Stripe knows no payment for it, or refuses
     */
    public function refund(PaymentMethod $method, string $sessionId, int $amount, string $currency, string $idempotencyKey): string
    {
        $intent = $this->session($method, $sessionId)['payment_intent'] ?? null;
        if (\is_array($intent)) {
            $intent = $intent['id'] ?? null;
        }
        if (!\is_string($intent) || '' === $intent) {
            throw new \RuntimeException('Stripe knows no payment for this session.');
        }

        /** @var PaymentIntentsGateway $intents */
        $intents = $this->create('Stripe\PaymentIntents', $method);
        $payment = $intents->fetchPaymentIntent(['paymentIntentReference' => $intent])->send()->getData();
        $charge = $payment['latest_charge'] ?? $payment['charges']['data'][0]['id'] ?? null;
        if (\is_array($charge)) {
            $charge = $charge['id'] ?? null;
        }
        if (!\is_string($charge) || '' === $charge) {
            throw new \RuntimeException('Stripe knows no charge for this payment.');
        }

        $request = $this->gateway($method)->refund(['transactionReference' => $charge, 'currency' => strtoupper($currency)]);
        $request->setAmountInteger($amount);
        $request->setIdempotencyKeyHeader($idempotencyKey);
        $response = $request->send();
        $data = $response->getData();
        if (!$response->isSuccessful() || empty($data['id']) || \in_array($data['status'] ?? '', ['failed', 'canceled'], true)) {
            throw new \RuntimeException((string) ($response->getMessage() ?? $data['failure_reason'] ?? 'refused'));
        }

        return (string) $data['id'];
    }

    private function gateway(PaymentMethod $method): CheckoutGateway
    {
        /** @var CheckoutGateway */
        return $this->create('\\'.CheckoutGateway::class, $method);
    }

    private function create(string $gateway, PaymentMethod $method): GatewayInterface
    {
        $gateway = Omnipay::create($gateway, $this->httpClient ? new OmnipayClient(new Psr18Client($this->httpClient)) : null);
        $gateway->setApiKey($this->apiKey($method));

        return $gateway;
    }

    private function apiKey(PaymentMethod $method): string
    {
        return (string) ($method->getGatewayParameters()['api_key'] ?? '');
    }
}
