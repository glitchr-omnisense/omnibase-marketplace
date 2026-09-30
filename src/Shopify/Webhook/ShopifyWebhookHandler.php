<?php

namespace Base\Marketplace\Shopify\Webhook;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\Shipping;
use Base\Marketplace\Shopify\Catalogue\InventorySynchronizer;
use Base\Marketplace\Shopify\Catalogue\ProductMapper;
use Base\Marketplace\Shopify\Catalogue\ProductSynchronizer;
use Base\Marketplace\Shopify\Checkout\DraftOrderMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Everything a Shopify webhook actually does.
 *
 * Split out of the controller so that the controller is only HTTP - signature,
 * status code - and all the behaviour can be tested without a kernel.
 *
 * An unknown topic is a success, not an error. Shopify retries a non-2xx for
 * 48 hours and DELETES the subscription after eight hours of continuous
 * failure, so answering 500 to a topic we did not expect is a way to lose the
 * ones we did.
 */
class ShopifyWebhookHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Checkout $checkout,
        private readonly ProductMapper $mapper,
        private readonly ProductSynchronizer $products,
        private readonly InventorySynchronizer $inventory,
        #[Autowire('%marketplace.shopify.catalogue.enabled%')] private readonly bool $catalogueEnabled = false,
        #[Autowire('%marketplace.shopify.checkout.enabled%')] private readonly bool $checkoutEnabled = false,
        private readonly ?Shipping $shipping = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> what to answer with
     */
    public function handle(string $topic, array $payload): array
    {
        return match ($topic) {
            'orders/paid' => $this->orderPaid($payload),
            'orders/cancelled', 'draft_orders/delete' => $this->orderCancelled($payload),
            'products/create', 'products/update' => $this->productChanged($payload),
            'products/delete' => $this->productDeleted($payload),
            'inventory_levels/update' => ['handled' => $this->catalogueEnabled && $this->inventory->handle($payload)],
            'fulfillments/create' => $this->fulfilled($payload),
            default => ['ignored' => true, 'topic' => $topic],
        };
    }

    private function orderPaid(array $payload): array
    {
        if (!$this->checkoutEnabled) {
            return ['ignored' => true];
        }

        [$order, $transaction] = $this->locate($payload);
        if (!$order || !$transaction) {
            // Somebody else's order, or one this shop never created. Not an
            // error: a shop may well sell through Shopify directly too.
            return ['ignored' => true, 'reason' => 'no matching order'];
        }

        $transaction->setDetails(array_merge($transaction->getDetails() ?? [], array_filter([
            'shopify_order_gid' => $this->gid('Order', $payload['admin_graphql_api_id'] ?? $payload['id'] ?? null),
            'shopify_order_name' => $payload['name'] ?? null,
        ])));

        // Idempotent: returns immediately on an order already confirmed, so
        // the webhook, the "check my payment" route and the reconcile command
        // may all arrive at once.
        $this->checkout->confirm($order, $transaction);

        return ['confirmed' => (string) $order->getReference()];
    }

    private function orderCancelled(array $payload): array
    {
        if (!$this->checkoutEnabled) {
            return ['ignored' => true];
        }

        [$order, $transaction] = $this->locate($payload);
        if (!$order || !$transaction) {
            return ['ignored' => true, 'reason' => 'no matching order'];
        }

        // cancel() already declines to touch a confirmed or completed order.
        $this->checkout->cancel($order, $transaction);

        return ['cancelled' => (string) $order->getReference()];
    }

    private function productChanged(array $payload): array
    {
        if (!$this->catalogueEnabled) {
            return ['ignored' => true];
        }

        // REST-shaped, even though the subscription was made over GraphQL.
        $results = [];
        foreach ($this->mapper->fromRestPayload($payload, $this->products->currency()) as $data) {
            $results[] = $this->products->synchronize($data);
        }
        $this->products->flush();

        return ['synchronized' => \count($results), 'results' => array_count_values($results)];
    }

    private function productDeleted(array $payload): array
    {
        if (!$this->catalogueEnabled) {
            return ['ignored' => true];
        }

        $gid = $this->gid('Product', $payload['admin_graphql_api_id'] ?? $payload['id'] ?? null);
        if (null === $gid) {
            return ['ignored' => true];
        }

        $count = $this->products->discontinue($gid);
        $this->products->flush();

        return ['discontinued' => $count];
    }

    private function fulfilled(array $payload): array
    {
        if (!$this->checkoutEnabled || null === $this->shipping) {
            return ['ignored' => true];
        }

        [$order] = $this->locate($payload['order'] ?? $payload);
        $tracking = $payload['tracking_number'] ?? null;
        if (!$order || !$tracking) {
            return ['ignored' => true];
        }

        try {
            $this->shipping->ship($order, (string) $tracking);
            // Shipping::ship() persists; a webhook has nobody else to flush it.
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger?->warning('Shopify fulfilment could not be applied: {message}', ['message' => $e->getMessage()]);

            return ['ignored' => true, 'reason' => 'not shippable'];
        }

        return ['shipped' => (string) $order->getReference()];
    }

    /**
     * Find the marketplace order this payload is about.
     *
     * By reference first - it is unique and indexed, and travels on the order
     * as a note attribute we put there - then by the draft order gid recorded
     * on the transaction, which is what an orders/paid coming out of a draft
     * order carries.
     *
     * @return array{0: ?Order, 1: ?Transaction}
     */
    private function locate(array $payload): array
    {
        $reference = $this->reference($payload);
        $order = null;

        if (null !== $reference) {
            $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $reference]);
        }

        $transactions = $this->entityManager->getRepository(Transaction::class);
        $transaction = null;

        if ($order) {
            foreach ($order->getTransactions() as $candidate) {
                if (!empty($candidate->getDetails()['shopify_draft_order']) || $candidate->getWebhook()) {
                    $transaction = $candidate;
                }
            }
            $transaction ??= $order->getTransactions()->last() ?: null;

            return [$order, $transaction];
        }

        $draftGid = $this->gid('DraftOrder', $payload['draft_order_id'] ?? null);
        if (null !== $draftGid) {
            $transaction = $transactions->findOneBy(['webhook' => $draftGid]);
            $order = $transaction?->getOrder();
        }

        return [$order, $transaction];
    }

    private function reference(array $payload): ?string
    {
        foreach ($payload['note_attributes'] ?? [] as $attribute) {
            if (DraftOrderMapper::REFERENCE_KEY === ($attribute['name'] ?? $attribute['key'] ?? null)) {
                return (string) ($attribute['value'] ?? '') ?: null;
            }
        }

        return $payload['source_identifier'] ?? null;
    }

    private function gid(string $type, mixed $id): ?string
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return str_starts_with((string) $id, 'gid://') ? (string) $id : sprintf('gid://shopify/%s/%s', $type, $id);
    }
}
