<?php

namespace Base\Marketplace\Shopify\Catalogue;

/**
 * One Shopify variant, flattened.
 *
 * The point of this object is that the two shapes Shopify sends - the
 * GraphQL node and the REST-shaped webhook payload - become the same thing
 * before anything looks at them. Whichever way a product arrives, the
 * synchroniser sees this.
 */
final class ProductData
{
    public function __construct(
        public readonly string $productGid,
        public readonly string $variantGid,
        public readonly ?string $legacyVariantId,
        public readonly string $title,
        public readonly ?string $variantTitle,
        public readonly ?string $description,
        public readonly ?string $handle,
        public readonly array $tags,
        public readonly string $status,
        public readonly bool $availableForSale,
        public readonly int $unitPrice,
        public readonly string $currency,
        public readonly ?string $sku,
        public readonly ?string $barcode,
        public readonly ?int $stock,
        public readonly ?string $inventoryItemId,
        public readonly ?string $imageUrl,
        public readonly bool $isOnlyVariant,
    ) {
    }

    /**
     * The hash the synchroniser compares against ProductLink::$fingerprint.
     *
     * Only the fields it is allowed to write go in: a product whose Shopify
     * description changed while the shop only owns the price must still come
     * out as unchanged, or every sync would rewrite it for nothing.
     *
     * @param string[] $ownedFields
     */
    public function fingerprint(array $ownedFields): string
    {
        $all = [
            'title' => $this->title . '|' . $this->variantTitle,
            'description' => $this->description,
            'slug' => $this->handle,
            'price' => $this->unitPrice . $this->currency,
            'stock' => $this->stock,
            'availability' => $this->status . '|' . ($this->availableForSale ? '1' : '0'),
            'identifiers' => $this->sku . '|' . $this->barcode,
            'tags' => implode(',', $this->tags),
        ];

        $owned = array_intersect_key($all, array_flip($ownedFields));
        ksort($owned);

        return hash('sha256', json_encode($owned));
    }
}
