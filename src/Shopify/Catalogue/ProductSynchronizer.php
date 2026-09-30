<?php

namespace Base\Marketplace\Shopify\Catalogue;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Marketplace\Entity\Product\Attribute\Barcode as BarcodeAttribute;
use Base\Marketplace\Entity\Product\Feature;
use Base\Marketplace\Entity\Product\Identifier;
use Base\Marketplace\Entity\Product\Variant;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\Barcode;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Marketplace\Shopify\Api\Endpoint;
use Base\Marketplace\Shopify\Entity\ProductLink;
use Base\Marketplace\Shopify\Repository\ProductLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes what the mapper produced into the catalogue.
 *
 * One way only, Shopify -> marketplace. Nothing here ever calls Shopify, and
 * nothing here writes to it; that halves the conflict surface and is the only
 * version worth having first.
 *
 * Three things keep it from trampling the shop's own work:
 *
 *   1. It writes only the fields listed in marketplace.shopify.catalogue.
 *      owned_fields. Taxa, channels, owners, image crops, anything an
 *      application's own Product subclass adds - never listed, never touched.
 *   2. A product whose owned fields hash the same as last time is skipped
 *      entirely: no write, no flush, no Thread::$updatedAt bump. Without this
 *      an hourly sync would invalidate every cache keyed on updatedAt
 *      (Product::__toKey() includes it) for nothing.
 *   3. A product tagged "shopify-unmanaged" is skipped whole. It is an
 *      ordinary Feature, so a shop manager can pin a product from the admin
 *      without anyone touching config.
 *
 * It never deletes. A marketplace Product is the inverse side of OrderItem and
 * Review; removing one would tear a hole in order history. A product that
 * disappears from Shopify is marked DISCONTINUED with no stock, and its link
 * row is kept as a tombstone with a null product - so a Shopify product
 * re-created under the same gid cannot silently adopt the old catalogue entry.
 */
class ProductSynchronizer
{
    public const UNMANAGED_TAG = 'shopify-unmanaged';

    /** @var array<string, int> counters for the command's report */
    private array $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'discontinued' => 0];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductLinkRepository $links,
        private readonly Endpoint $endpoint,
        #[Autowire('%marketplace.shopify.catalogue.store%')] private readonly ?string $storeSlug = null,
        #[Autowire('%marketplace.shopify.catalogue.merchant%')] private readonly ?string $merchantId = null,
        #[Autowire('%marketplace.shopify.catalogue.owned_fields%')] private readonly array $ownedFields = [],
        #[Autowire('%marketplace.default_currency%')] private readonly string $defaultCurrency = 'EUR',
    ) {
    }

    public function stats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = array_map(static fn () => 0, $this->stats);
    }

    public function currency(): string
    {
        return $this->store()?->getCurrency() ?: $this->defaultCurrency;
    }

    /**
     * Bring one Shopify variant into the catalogue.
     *
     * @param bool $dryRun report what would change and write nothing
     *
     * @return string one of created|updated|unchanged|skipped
     */
    public function synchronize(ProductData $data, bool $dryRun = false): string
    {
        $shop = $this->endpoint->shop();
        $link = $this->links->findOneByVariant($shop, $data->variantGid);
        $product = $link?->getProduct();

        if ($product && $this->isUnmanaged($product)) {
            ++$this->stats['skipped'];

            return 'skipped';
        }

        $fingerprint = $data->fingerprint($this->ownedFields);
        if ($link && $product && $link->matches($fingerprint)) {
            ++$this->stats['unchanged'];

            return 'unchanged';
        }

        $created = null === $product;
        if ($created) {
            // A first import may still find the product: a shop that already
            // sells this item by SKU should gain a link, not a duplicate.
            $product = $this->findBySku($data) ?? $this->create($data);
            $created = null === $product->getId();
        }

        if ($dryRun) {
            ++$this->stats[$created ? 'created' : 'updated'];

            return $created ? 'created' : 'updated';
        }

        $this->apply($product, $data);

        $link ??= new ProductLink($shop, $data->productGid, $data->variantGid);
        $link->setProductGid($data->productGid)
            ->setLegacyVariantId($data->legacyVariantId)
            ->setInventoryItemId($data->inventoryItemId)
            ->setProduct($product)
            ->setFingerprint($fingerprint)
            ->touch();

        $this->entityManager->persist($product);
        $this->entityManager->persist($link);

        ++$this->stats[$created ? 'created' : 'updated'];

        return $created ? 'created' : 'updated';
    }

    /**
     * The product is gone from Shopify. Retire it; never delete it.
     */
    public function discontinue(string $productGid): int
    {
        $count = 0;
        foreach ($this->links->findByProductGid($this->endpoint->shop(), $productGid) as $link) {
            $product = $link->getProduct();
            if ($product && !$this->isUnmanaged($product)) {
                $product->setAvailability(ProductAvailability::DISCONTINUED);
                $product->setStock(0);
                $this->entityManager->persist($product);
                ++$count;
            }

            // Kept as a tombstone: the row records that this gid was once
            // ours, so a re-created product does not inherit the old one.
            $link->setProduct(null)->setFingerprint(null)->touch();
            $this->entityManager->persist($link);
        }

        $this->stats['discontinued'] += $count;

        return $count;
    }

    /** Stock only - what inventory_levels/update can tell us. */
    public function updateStock(string $inventoryItemId, ?int $available): bool
    {
        $link = $this->links->findOneByInventoryItem($this->endpoint->shop(), $inventoryItemId);
        $product = $link?->getProduct();
        if (!$product || $this->isUnmanaged($product) || !$this->owns('stock')) {
            return false;
        }

        $product->setStock($available);
        // Stock and availability are two fields in this bundle and one idea in
        // Shopify; leaving them to disagree is how a sold-out product stays
        // buyable.
        if ($this->owns('availability')) {
            $product->setAvailability(
                (null === $available || $available > 0) ? ProductAvailability::INSTOCK : ProductAvailability::OUT_OF_STOCK
            );
        }

        // The fingerprint no longer describes what is stored.
        $link->setFingerprint(null)->touch();
        $this->entityManager->persist($product);
        $this->entityManager->persist($link);

        return true;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    // -----------------------------------------------------------------------

    private function owns(string $field): bool
    {
        return \in_array($field, $this->ownedFields, true);
    }

    private function isUnmanaged(Product $product): bool
    {
        foreach ($product->getFeatures() as $feature) {
            if (self::UNMANAGED_TAG === $feature->getSlug() || self::UNMANAGED_TAG === (string) $feature->getLabel()) {
                return true;
            }
        }

        return false;
    }

    private function store(): ?Store
    {
        if (null === $this->storeSlug) {
            return null;
        }

        return $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $this->storeSlug]);
    }

    private function create(ProductData $data): Product
    {
        $store = $this->store();
        $currency = $store?->getCurrency() ?: $this->defaultCurrency;

        // A variant of a product already linked becomes a Variant of it, so
        // that a Shopify product with options stays one product in the shop.
        $principal = null;
        if (!$data->isOnlyVariant) {
            foreach ($this->links->findByProductGid($this->endpoint->shop(), $data->productGid) as $sibling) {
                $candidate = $sibling->getProduct();
                if ($candidate && !$candidate instanceof Variant) {
                    $principal = $candidate;
                    break;
                }
            }
        }

        $product = $principal ? new Variant($principal, $data->unitPrice, $currency) : new Product(null, $store, $data->unitPrice, $currency);

        if (!$principal && $store) {
            $product->setStore($store);
        }

        if (null !== $this->merchantId) {
            $merchant = $this->entityManager->getRepository(\Base\Entity\User::class)->find($this->merchantId);
            if ($merchant) {
                $product->addOwner($merchant);
            }
        }

        return $product;
    }

    /**
     * A product already in the catalogue carrying this SKU. The SKU barcode is
     * the only identifier both sides already agree on before any link exists,
     * which makes it the right key for a first import and the wrong one for
     * anything after - once linked, the gid rules.
     */
    private function findBySku(ProductData $data): ?Product
    {
        if (null === $data->sku) {
            return null;
        }

        $barcode = $this->entityManager->getRepository(BarcodeAttribute::class)
            ->findOneBy(['value' => Barcode::SKU . ' ' . str_replace(' ', '', $data->sku)]);

        return $barcode?->getIdentifier()?->getProduct();
    }

    private function apply(Product $product, ProductData $data): void
    {
        if ($this->owns('title')) {
            $title = $data->variantTitle ? $data->title . ' - ' . $data->variantTitle : $data->title;
            $product->setTitle($title);
        }

        if ($this->owns('description') && null !== $data->description) {
            $product->setContent($data->description);
        }

        if ($this->owns('slug') && null !== $data->handle) {
            $product->setSlug($this->uniqueSlug($data, $product));
        }

        if ($this->owns('price')) {
            $product->setUnitPrice($data->unitPrice);
            $product->setCurrency($this->store()?->getCurrency() ?: $data->currency);
        }

        if ($this->owns('stock')) {
            $product->setStock($data->stock);
        }

        if ($this->owns('availability')) {
            $product->setAvailability($this->availability($data));
        }

        if ($this->owns('identifiers')) {
            $this->applyIdentifiers($product, $data);
        }

        if ($this->owns('tags')) {
            $this->applyTags($product, $data);
        }
    }

    /**
     * Shopify's status and per-variant availability, as one of this bundle's
     * availabilities.
     *
     * OUT_OF_STOCK rather than SOLDOUT for an active-but-unavailable product:
     * Product::isForSell() excludes both, and OUT_OF_STOCK reads truer for
     * something that will be restocked.
     */
    private function availability(ProductData $data): string
    {
        if ('ACTIVE' !== $data->status) {
            return ProductAvailability::DISCONTINUED;
        }

        if (!$data->availableForSale) {
            return ProductAvailability::OUT_OF_STOCK;
        }

        return ProductAvailability::INSTOCK;
    }

    /**
     * Thread::$slug is unique across the table, and two shops' handles can
     * collide. The legacy id is the disambiguator because it is stable - a
     * counter would move the slug every time the sync ran.
     */
    private function uniqueSlug(ProductData $data, Product $product): string
    {
        $slug = (string) $data->handle;
        $existing = $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);

        if (null === $existing || $existing === $product) {
            return $slug;
        }

        return $slug . '-' . ($data->legacyVariantId ?? substr(hash('crc32b', $data->variantGid), 0, 6));
    }

    private function applyIdentifiers(Product $product, ProductData $data): void
    {
        $wanted = array_filter([
            Barcode::SKU => $data->sku,
            $this->barcodeStandard($data->barcode) => $data->barcode,
        ]);

        if (!$wanted) {
            return;
        }

        $identifier = $product->getIdentifiers()->first() ?: null;
        if (!$identifier) {
            $identifier = new Identifier();
            $identifier->setProduct($product);
            $product->getIdentifiers()->add($identifier);
        }

        foreach ($wanted as $standard => $value) {
            $existing = $identifier->getBarcode($standard);
            if ($existing) {
                $existing->setValue($value);
                continue;
            }

            $adapter = new BarcodeAdapter();
            $adapter->setStandard($standard);
            $identifier->addBarcode(new BarcodeAttribute($adapter, $value));
        }
    }

    /** EAN at 13 digits, UPC at 12, GTIN for anything else. */
    private function barcodeStandard(?string $barcode): string
    {
        $digits = preg_replace('/\D/', '', (string) $barcode);

        return match (\strlen((string) $digits)) {
            13 => Barcode::EAN,
            12 => Barcode::UPC,
            default => Barcode::GTIN,
        };
    }

    private function applyTags(Product $product, ProductData $data): void
    {
        $repository = $this->entityManager->getRepository(Feature::class);

        foreach ($data->tags as $label) {
            $slug = $this->slugify($label);
            if (self::UNMANAGED_TAG === $slug) {
                continue;
            }

            $feature = $repository->findOneBy(['slug' => $slug]);
            if (!$feature) {
                $feature = new Feature();
                $feature->setLabel($label);
                $feature->setSlug($slug);
                $this->entityManager->persist($feature);
            }

            $product->addFeature($feature);
        }
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim((string) $value, '-');
    }
}
