<?php

namespace Base\Market\Shopify\Checkout;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Payment\PaymentGatewayInterface;
use Base\Market\Payment\PaymentResult;
use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Api\ShopifyApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Payment through a Shopify draft order's invoice page.
 *
 * The buyer is sent to Shopify, pays there, and the orders/paid webhook
 * confirms the order back here. Settings come from market.shopify.* (the shop
 * and its token, which are infrastructure) while the payment method's own
 * gateway_parameters carry policy - `currencies`, which
 * PaymentGatewayRegistry::usableFor() already honours.
 *
 * Two things this does that StripeGateway does not, both deliberate:
 *
 *   - it catches its own transport failures and returns refused() rather than
 *     letting an exception out. Checkout::pay() would roll the order back to
 *     a cart correctly either way, but CartController only catches
 *     CartException, so an uncaught one means a 500 page over an intact cart.
 *     A refusal becomes a flash message and a second chance.
 *   - there is no return leg to speak of. A Shopify invoice checkout ends on
 *     Shopify's own thank-you page; neither draft orders nor Storefront carts
 *     accept a return URL outside Plus. Confirmation is therefore the webhook
 *     first, with the "check my payment" route and the reconcile command as
 *     the two safety nets. See OrderReconciler.
 */
final class ShopifyGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly AdminApi $api,
        private readonly DraftOrderMapper $mapper,
        #[Autowire('%market.shopify.checkout.enabled%')] private readonly bool $enabled = false,
        #[Autowire('%market.shopify.checkout.draft_order_tags%')] private readonly array $tags = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function name(): string
    {
        return 'shopify';
    }

    /**
     * Off, or not configured, means the method never appears at checkout -
     * the same quiet degradation an empty Stripe key produces, rather than an
     * error on a page the buyer cannot do anything about.
     */
    public function supports(Order $order, PaymentMethod $method): bool
    {
        return $this->enabled && $this->api->isConfigured();
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        try {
            $shop = $this->api->endpoint()->shop();
            $result = $this->api->mutate(
                DraftOrderMapper::CREATE,
                ['input' => $this->mapper->map($order, $shop, $this->tags)],
                'draftOrderCreate',
            );
        } catch (ShopifyApiException $e) {
            $this->logger?->error('Shopify draft order failed: {message}', ['message' => $e->getMessage(), 'order' => $order->getReference()]);

            return PaymentResult::refused('shopify.unavailable', ['{reason}' => $e->getMessage()]);
        }

        $draft = $result['draftOrder'] ?? [];
        $gid = $draft['id'] ?? null;
        $invoiceUrl = $draft['invoiceUrl'] ?? null;

        if (!$gid || !$invoiceUrl) {
            return PaymentResult::refused('shopify.unavailable', ['{reason}' => 'no invoice URL']);
        }

        // The draft order gid is how the return page, the webhook and the
        // reconcile sweep all find this transaction again - the same job
        // StripeGateway gives the Checkout session id.
        $transaction->setWebhook($gid);
        $transaction->setDetails(array_merge($transaction->getDetails() ?? [], [
            'shopify_draft_order' => $gid,
            'shopify_draft_name' => $draft['name'] ?? null,
            'shopify_shop' => $shop,
        ]));

        return PaymentResult::redirect($invoiceUrl);
    }
}
