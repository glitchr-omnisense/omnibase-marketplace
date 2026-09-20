<?php

namespace Base\Market\Shopify\Entity;

use Base\Market\Entity\Product;
use Base\Market\Shopify\Repository\ProductLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a market Product is, on the Shopify side.
 *
 * A table of its own rather than a column on Product, for three reasons that
 * a column cannot meet at once:
 *
 *   - it is per VARIANT, and a Product cannot hold a row per variant of
 *     itself;
 *   - the inventory_levels/update webhook identifies what changed by
 *     inventoryItemId and by nothing else, so that id has to be indexed
 *     somewhere;
 *   - a column on market_product would exist in every host's schema, Shopify
 *     or not, and the whole point of this integration is that it leaves no
 *     trace when it is off. This table is mapped only when
 *     market.shopify.enabled is true (see MarketExtension::prepend()).
 *
 * It doubles as the delta store: `fingerprint` is a hash of the fields the
 * synchroniser owns, so an unchanged product costs one comparison and no
 * write - which matters because a write would bump Thread::$updatedAt and
 * churn every cache keyed on it.
 *
 * A row outlives the product it points at. `product` is nulled rather than
 * the row deleted when a Shopify product goes away, so a re-created product
 * carrying the same gid cannot silently adopt an old catalogue entry.
 */
#[ORM\Entity(repositoryClass: ProductLinkRepository::class)]
#[ORM\Table(name: 'marketShopifyProductLink')]
#[ORM\UniqueConstraint(name: 'shopify_variant_unique', columns: ['shop', 'variantGid'])]
#[ORM\Index(name: 'shopify_inventory_item_idx', columns: ['inventoryItemId'])]
#[ORM\Index(name: 'shopify_product_gid_idx', columns: ['productGid'])]
class ProductLink
{
    public function __construct(string $shop = '', string $productGid = '', string $variantGid = '')
    {
        $this->shop = $shop;
        $this->productGid = $productGid;
        $this->variantGid = $variantGid;
        $this->syncedAt = new \DateTime();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The myshopify.com domain this link belongs to. */
    #[ORM\Column(type: 'string', length: 255)]
    protected $shop;

    public function getShop(): string
    {
        return (string) $this->shop;
    }

    public function setShop(string $shop): self
    {
        $this->shop = $shop;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255)]
    protected $productGid;

    public function getProductGid(): string
    {
        return (string) $this->productGid;
    }

    public function setProductGid(string $productGid): self
    {
        $this->productGid = $productGid;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255)]
    protected $variantGid;

    public function getVariantGid(): string
    {
        return (string) $this->variantGid;
    }

    public function setVariantGid(string $variantGid): self
    {
        $this->variantGid = $variantGid;

        return $this;
    }

    /**
     * The numeric id the REST-shaped webhooks use. products/update carries
     * "id": 123456 where the GraphQL API says "gid://shopify/Product/123456",
     * and one of the two has to be resolvable to the other.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected $legacyVariantId;

    public function getLegacyVariantId(): ?string
    {
        return $this->legacyVariantId;
    }

    public function setLegacyVariantId(?string $legacyVariantId): self
    {
        $this->legacyVariantId = $legacyVariantId;

        return $this;
    }

    /** The only identifier inventory_levels/update gives you. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected $inventoryItemId;

    public function getInventoryItemId(): ?string
    {
        return $this->inventoryItemId;
    }

    public function setInventoryItemId(?string $inventoryItemId): self
    {
        $this->inventoryItemId = $inventoryItemId;

        return $this;
    }

    /**
     * SET NULL, not CASCADE: a link whose product is gone is a tombstone, and
     * deleting market products is not something this integration ever does
     * anyway - they are the inverse side of OrderItem and Review.
     */
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Product::class)]
    protected $product;

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    /** sha256 of the owned fields as last written. Null forces a resync. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected $fingerprint;

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(?string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function matches(?string $fingerprint): bool
    {
        return null !== $this->fingerprint && $this->fingerprint === $fingerprint;
    }

    #[ORM\Column(type: 'datetime')]
    protected $syncedAt;

    public function getSyncedAt(): ?\DateTimeInterface
    {
        return $this->syncedAt;
    }

    public function touch(): self
    {
        $this->syncedAt = new \DateTime();

        return $this;
    }

    public function __toString(): string
    {
        return $this->getVariantGid();
    }
}
