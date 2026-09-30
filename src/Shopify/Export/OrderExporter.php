<?php

namespace Base\Marketplace\Shopify\Export;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Shopify\Api\AdminApi;
use Base\Marketplace\Shopify\Api\ShopifyApiException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the Shopify order and records where it went.
 *
 * Idempotent by inspection: a transaction already carrying a
 * shopify_order_gid means this order has been pushed, and a second attempt -
 * a Messenger retry, a replayed event, someone running the command twice -
 * does nothing. Shopify's own sourceIdentifier is the backstop.
 */
class OrderExporter
{
    public function __construct(
        private readonly AdminApi $api,
        private readonly OrderMapper $mapper,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%marketplace.shopify.export.only_shippable%')] private readonly bool $onlyShippable = true,
        #[Autowire('%marketplace.shopify.export.location%')] private readonly ?string $location = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether this order is one we push at all.
     *
     * Two orders are not: one Shopify created itself (it is already there,
     * and pushing it would duplicate the sale), and one made entirely of
     * things that never ship - a downloaded file, an in-game item - where a
     * fulfilment record would mean nothing to anybody.
     */
    public function shouldExport(Order $order): bool
    {
        if ('shopify' === $order->getPaymentMethod()?->getGatewayFactory()) {
            return false;
        }

        if ($this->alreadyExported($order)) {
            return false;
        }

        if ($this->onlyShippable) {
            $shippable = false;
            foreach ($order->getItems() as $item) {
                if ($item->getProduct()?->isShippable()) {
                    $shippable = true;
                    break;
                }
            }

            if (!$shippable) {
                return false;
            }
        }

        return true;
    }

    public function alreadyExported(Order $order): bool
    {
        foreach ($order->getTransactions() as $transaction) {
            if (!empty($transaction->getDetails()['shopify_order_gid'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null the Shopify order gid, or null when nothing was done
     *
     * @throws ShopifyApiException so that Messenger retries rather than
     *                             swallowing a failure
     */
    public function export(Order $order): ?string
    {
        if (!$this->api->isConfigured() || !$this->shouldExport($order)) {
            return null;
        }

        $shop = $this->api->endpoint()->shop();
        $payload = $this->mapper->map($order, $shop, $this->location);

        $result = $this->api->mutate(OrderMapper::CREATE, $payload, 'orderCreate');
        $gid = $result['order']['id'] ?? null;
        if (!$gid) {
            return null;
        }

        // Recorded on the transaction, where StripeGateway and ManualGateway
        // already keep their own gateway payloads: Transaction::$details is a
        // json column and needs no schema change to hold this.
        $transaction = $order->getTransactions()->last() ?: null;
        if ($transaction) {
            $transaction->setDetails(array_merge($transaction->getDetails() ?? [], array_filter([
                'shopify_order_gid' => $gid,
                'shopify_order_name' => $result['order']['name'] ?? null,
                'shopify_shop' => $shop,
            ])));
            $this->entityManager->flush();
        }

        $this->logger?->info('Order {reference} pushed to Shopify as {gid}', ['reference' => $order->getReference(), 'gid' => $gid]);

        return $gid;
    }
}
