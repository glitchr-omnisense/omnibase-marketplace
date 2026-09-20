<?php

namespace Base\Market\Shopify\Catalogue;

use Base\Market\Shopify\Api\Money;

/**
 * Shopify's two product shapes -> ProductData.
 *
 * Pure: arrays in, objects out. No entity manager, no HTTP client, no clock.
 * That is what lets the whole mapping be tested against recorded payloads
 * with no kernel and no database, and it is worth keeping that way.
 *
 * There are two entry points because Shopify sends two different shapes for
 * the same product and does not warn you:
 *
 *   fromGraphQLNode()  what the products(...) query returns - "id" is a gid,
 *                      variants are under variants.nodes, price is a string.
 *   fromRestPayload()  what the products/create|update webhook POSTs - "id"
 *                      is a bare number, "variants" is a flat array, and the
 *                      field names are snake_case.
 *
 * The webhook payload is REST-shaped even when the subscription was created
 * through GraphQL. Discovering that halfway through is the usual way this
 * integration breaks, so both shapes converge here and the test asserts they
 * produce the same ProductData for the same product.
 */
class ProductMapper
{
    /**
     * @param array<string, mixed> $node a products(...).nodes[] entry
     *
     * @return ProductData[] one per variant
     */
    public function fromGraphQLNode(array $node, string $defaultCurrency): array
    {
        $variants = $node['variants']['nodes'] ?? [];
        $count = \count($variants);
        $out = [];

        foreach ($variants as $variant) {
            $tracked = (bool) ($variant['inventoryItem']['tracked'] ?? false);

            $out[] = new ProductData(
                productGid: (string) ($node['id'] ?? ''),
                variantGid: (string) ($variant['id'] ?? ''),
                legacyVariantId: $this->legacyId($variant['id'] ?? null),
                title: (string) ($node['title'] ?? ''),
                variantTitle: $this->variantTitle($variant['title'] ?? null),
                description: $node['descriptionHtml'] ?? null,
                handle: $node['handle'] ?? null,
                tags: $this->tags($node['tags'] ?? []),
                status: strtoupper((string) ($node['status'] ?? 'ACTIVE')),
                availableForSale: (bool) ($variant['availableForSale'] ?? false),
                unitPrice: Money::toMinor($variant['price'] ?? null, $defaultCurrency),
                currency: strtoupper($defaultCurrency),
                sku: $this->blankToNull($variant['sku'] ?? null),
                barcode: $this->blankToNull($variant['barcode'] ?? null),
                // Untracked inventory means "as many as you like", which is
                // exactly what a null stock means to this bundle.
                stock: $tracked ? (int) ($variant['inventoryQuantity'] ?? 0) : null,
                inventoryItemId: $this->blankToNull($variant['inventoryItem']['id'] ?? null),
                imageUrl: $variant['image']['url'] ?? ($node['featuredMedia']['image']['url'] ?? null),
                isOnlyVariant: 1 === $count,
            );
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload a products/create|update webhook body
     *
     * @return ProductData[] one per variant
     */
    public function fromRestPayload(array $payload, string $defaultCurrency): array
    {
        $variants = $payload['variants'] ?? [];
        $count = \count($variants);
        $out = [];

        // The REST shape has no per-variant availability flag: a variant is
        // for sale when the product is active and it has stock (or does not
        // track it at all).
        $status = strtoupper((string) ($payload['status'] ?? 'active'));

        foreach ($variants as $variant) {
            $tracked = null !== ($variant['inventory_management'] ?? null) && '' !== $variant['inventory_management'];
            $quantity = (int) ($variant['inventory_quantity'] ?? 0);

            $out[] = new ProductData(
                productGid: $this->gid('Product', $payload['id'] ?? null),
                variantGid: $this->gid('ProductVariant', $variant['id'] ?? null),
                legacyVariantId: null !== ($variant['id'] ?? null) ? (string) $variant['id'] : null,
                title: (string) ($payload['title'] ?? ''),
                variantTitle: $this->variantTitle($variant['title'] ?? null),
                description: $payload['body_html'] ?? null,
                handle: $payload['handle'] ?? null,
                tags: $this->tags($payload['tags'] ?? []),
                status: $status,
                availableForSale: 'ACTIVE' === $status && (!$tracked || $quantity > 0),
                unitPrice: Money::toMinor($variant['price'] ?? null, $defaultCurrency),
                currency: strtoupper($defaultCurrency),
                sku: $this->blankToNull($variant['sku'] ?? null),
                barcode: $this->blankToNull($variant['barcode'] ?? null),
                stock: $tracked ? $quantity : null,
                inventoryItemId: null !== ($variant['inventory_item_id'] ?? null)
                    ? $this->gid('InventoryItem', $variant['inventory_item_id'])
                    : null,
                imageUrl: $this->restImage($payload, $variant),
                isOnlyVariant: 1 === $count,
            );
        }

        return $out;
    }

    /**
     * "Default Title" is what Shopify calls the single variant of a product
     * that has no options. It is not a name anyone wants on a product page.
     */
    private function variantTitle(?string $title): ?string
    {
        $title = trim((string) $title);

        return ('' === $title || 'Default Title' === $title) ? null : $title;
    }

    /** GraphQL gives an array of tags, REST a comma-separated string. */
    private function tags(mixed $tags): array
    {
        if (\is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return array_values(array_filter(array_map('trim', (array) $tags), static fn ($t) => '' !== $t));
    }

    private function restImage(array $payload, array $variant): ?string
    {
        $imageId = $variant['image_id'] ?? null;
        foreach ($payload['images'] ?? [] as $image) {
            if (null !== $imageId && ($image['id'] ?? null) === $imageId) {
                return $image['src'] ?? null;
            }
        }

        return $payload['image']['src'] ?? ($payload['images'][0]['src'] ?? null);
    }

    private function gid(string $type, mixed $id): string
    {
        if (null === $id || '' === $id) {
            return '';
        }

        // Already a gid (a webhook that did carry one): leave it alone.
        return str_starts_with((string) $id, 'gid://') ? (string) $id : sprintf('gid://shopify/%s/%s', $type, $id);
    }

    private function legacyId(?string $gid): ?string
    {
        if (null === $gid || '' === $gid) {
            return null;
        }

        $parts = explode('/', $gid);
        $last = end($parts);

        return '' !== $last ? $last : null;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = null === $value ? null : trim((string) $value);

        return ('' === $value || null === $value) ? null : $value;
    }
}
