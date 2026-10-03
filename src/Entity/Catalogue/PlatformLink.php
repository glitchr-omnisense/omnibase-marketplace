<?php

namespace Base\Marketplace\Entity\Catalogue;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product of this site and its original on a platform (an omnitrade
 * gateway reading a catalogue: Stripe, Shopify, WooCommerce): which gateway,
 * the product's and the variant's ids there, the stock item counted there,
 * and a fingerprint of what was last written - a product that did not
 * change there is not written again.
 *
 * One row per variant: a platform product with three variants is a Product
 * and two Variants here, three links. It replaces the Shopify-only
 * ProductLink of the former src/Shopify module (table marketplace_shopify_link).
 */
#[ORM\Entity(repositoryClass: PlatformLinkRepository::class)]
#[ORM\Table(name: 'marketplace_platform_link')]
#[ORM\UniqueConstraint(name: 'marketplace_platform_link_remote', columns: ['gateway', 'remoteVariant'])]
#[ORM\Index(name: 'marketplace_platform_link_product', columns: ['gateway', 'remoteProduct'])]
class PlatformLink
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** The omnitrade gateway's name (omnitrade.gateways.<name>). */
    #[ORM\Column(length: 64)]
    private string $gateway;

    #[ORM\Column(length: 191)]
    private string $remoteProduct;

    /** The variant's id there; the product's own id for a product with one variant. */
    #[ORM\Column(length: 191)]
    private string $remoteVariant;

    /** The counted item there (a Shopify inventory item), for stock events. */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $remoteItem = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 64)]
    private string $fingerprint = '';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remoteUpdatedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $syncedAt;

    /** Removed there (or no longer active): the product here is taken off sale, not deleted. */
    #[ORM\Column]
    private bool $orphaned = false;

    public function __construct(Product $product, string $gateway, string $remoteProduct, string $remoteVariant)
    {
        $this->product = $product;
        $this->gateway = $gateway;
        $this->remoteProduct = $remoteProduct;
        $this->remoteVariant = $remoteVariant;
        $this->syncedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getGateway(): string { return $this->gateway; }
    public function getRemoteProduct(): string { return $this->remoteProduct; }
    public function getRemoteVariant(): string { return $this->remoteVariant; }

    public function getRemoteItem(): ?string { return $this->remoteItem; }
    public function setRemoteItem(?string $remoteItem): self { $this->remoteItem = $remoteItem; return $this; }

    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }

    public function getFingerprint(): string { return $this->fingerprint; }

    public function isOrphaned(): bool { return $this->orphaned; }
    public function setOrphaned(bool $orphaned): self { $this->orphaned = $orphaned; return $this; }

    public function getRemoteUpdatedAt(): ?\DateTimeImmutable { return $this->remoteUpdatedAt; }
    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }

    /** Written now from what the platform said. */
    public function synced(string $fingerprint, ?\DateTimeImmutable $remoteUpdatedAt = null): self
    {
        $this->fingerprint = $fingerprint;
        $this->remoteUpdatedAt = $remoteUpdatedAt;
        $this->syncedAt = new \DateTimeImmutable();
        $this->orphaned = false;

        return $this;
    }
}
