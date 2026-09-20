<?php

namespace Base\Market\Shopify\Checkout;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Service\Checkout;
use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Api\ShopifyApiException;
use Psr\Log\LoggerInterface;

/**
 * Asks Shopify what became of a draft order, and settles the market order to
 * match.
 *
 * This exists because a Shopify invoice checkout has no return leg: the buyer
 * pays on Shopify's page and stays there. The orders/paid webhook is the
 * primary confirmation, and this is what covers the two cases it does not:
 * the buyer who comes back to the shop and wants an answer now, and the
 * webhook that never arrived because the site was down, the tunnel was shut,
 * or nobody had installed the subscription yet.
 *
 * It is also what makes the whole integration usable with no webhooks at all,
 * which is the difference between being able to develop against a Shopify
 * store and not.
 *
 * Safe to race with the webhook: Checkout::confirm() returns early on an
 * order that is already confirmed.
 */
class OrderReconciler
{
    public function __construct(
        private readonly AdminApi $api,
        private readonly Checkout $checkout,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return string one of paid|pending|cancelled|unknown
     */
    public function reconcile(Order $order, Transaction $transaction): string
    {
        $gid = $transaction->getDetails()['shopify_draft_order'] ?? $transaction->getWebhook();
        if (!$gid) {
            return 'unknown';
        }

        try {
            $data = $this->api->query(DraftOrderMapper::FETCH, ['id' => $gid]);
        } catch (ShopifyApiException $e) {
            $this->logger?->warning('Shopify reconcile failed: {message}', ['message' => $e->getMessage()]);

            return 'unknown';
        }

        $draft = $data['draftOrder'] ?? null;
        if (null === $draft) {
            // The draft was deleted on Shopify: the sale is not happening.
            $this->checkout->cancel($order, $transaction);

            return 'cancelled';
        }

        $status = strtoupper((string) ($draft['status'] ?? ''));
        $financial = strtoupper((string) ($draft['order']['displayFinancialStatus'] ?? ''));

        if ('COMPLETED' === $status && \in_array($financial, ['PAID', 'PARTIALLY_REFUNDED', ''], true)) {
            $transaction->setDetails(array_merge($transaction->getDetails() ?? [], array_filter([
                'shopify_order_gid' => $draft['order']['id'] ?? null,
                'shopify_order_name' => $draft['order']['name'] ?? null,
            ])));
            $this->checkout->confirm($order, $transaction);

            return 'paid';
        }

        return 'pending';
    }
}
