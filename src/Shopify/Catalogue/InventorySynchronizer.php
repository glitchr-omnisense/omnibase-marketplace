<?php

namespace Base\Marketplace\Shopify\Catalogue;

/**
 * inventory_levels/update -> stock.
 *
 * Its own class rather than a method on ProductSynchronizer because the
 * webhook it serves is the loudest one Shopify sends - a bulk edit in the
 * admin can fire hundreds - and it does the smallest possible amount of work:
 * one indexed lookup on inventoryItemId, one integer written, no mapping.
 */
class InventorySynchronizer
{
    public function __construct(private readonly ProductSynchronizer $products)
    {
    }

    /**
     * @param array<string, mixed> $payload the webhook body
     */
    public function handle(array $payload): bool
    {
        $inventoryItemId = $payload['inventory_item_id'] ?? null;
        if (null === $inventoryItemId) {
            return false;
        }

        // The webhook sends the bare numeric id; the link stores the gid.
        $gid = str_starts_with((string) $inventoryItemId, 'gid://')
            ? (string) $inventoryItemId
            : sprintf('gid://shopify/InventoryItem/%s', $inventoryItemId);

        $available = \array_key_exists('available', $payload) ? (int) $payload['available'] : null;

        $changed = $this->products->updateStock($gid, $available);
        if ($changed) {
            $this->products->flush();
        }

        return $changed;
    }
}
