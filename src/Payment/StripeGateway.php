<?php

namespace Base\Market\Payment;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;
use Base\Service\SettingBagInterface;
use Base\Market\Payment\Stripe\CheckoutGateway;
use Omnipay\Omnipay;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Card payment through Stripe Checkout, the hosted payment page: the member
 * is sent to Stripe, then back to market_stripe_return; the webhook
 * (market_stripe_webhook) confirms the order even if they never come back.
 *
 * Built on omnipay/stripe's Checkout gateway, as a shop site is on
 * Omnipay. Only registered when that package is installed.
 *
 * Settings, under market.gateways.<method slug>:
 *   api_key         the secret key (sk_live_... / sk_test_...)
 *   webhook_secret  the signing secret of the webhook endpoint (whsec_...) -
 *                   optional: market:stripe:webhook creates the endpoint and
 *                   stores its secret in the settings, read when this is empty
 *   webhook_url     optional, the endpoint's public address for that command
 *   currencies      optional, the currencies the method takes
 *   adaptive_pricing  optional, true to let Stripe offer the buyer's own
 *                   currency (Adaptive Pricing); off, the order's currency only
 *
 * The buyer's email is filled in on Stripe's page.
 */
final class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly ?SettingBagInterface $settings = null,
    ) {
    }

    /** Where market:stripe:webhook keeps the secret of the endpoint it created for $method. */
    public static function webhookSecretSetting(PaymentMethod $method): string
    {
        return 'market.stripe.'.str_replace(['-', '.'], '_', (string) $method->getSlug()).'.webhook_secret';
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
        $lines = [];
        foreach ($order->getItems() as $item) {
            $lines[] = [
                'quantity' => (int) $item->getQuantity(),
                'price_data' => [
                    'currency' => strtolower((string) $order->getCurrency()),
                    'unit_amount' => (int) $item->getUnitPrice(),
                    'product_data' => ['name' => (string) ($item->getProduct() ?? $item)],
                ],
            ];
        }

        $return = fn (array $query) => $this->urls->generate('market_stripe_return', ['order' => $order->getId()] + $query, UrlGeneratorInterface::ABSOLUTE_URL);
        $data = $this->gateway($method)->purchase([
            'mode' => 'payment',
            'line_items' => $lines,
            'customerEmail' => $order->getCustomer()?->getEmail(),
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

    private function gateway(PaymentMethod $method): CheckoutGateway
    {
        /** @var CheckoutGateway $gateway */
        $gateway = Omnipay::create('\\'.CheckoutGateway::class);
        $gateway->setApiKey($this->apiKey($method));

        return $gateway;
    }

    private function apiKey(PaymentMethod $method): string
    {
        return (string) ($method->getGatewayParameters()['api_key'] ?? '');
    }
}
