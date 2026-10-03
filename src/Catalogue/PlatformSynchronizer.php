<?php

namespace Base\Marketplace\Catalogue;

use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Marketplace\Entity\Brand;
use Base\Marketplace\Entity\Catalogue\PlatformLink;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Attribute;
use Base\Marketplace\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Marketplace\Entity\Product\Attribute\Barcode as BarcodeAttribute;
use Base\Marketplace\Entity\Product\Feature;
use Base\Marketplace\Entity\Product\Identifier;
use Base\Marketplace\Entity\Product\Variant;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\Barcode;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Product as RemoteProduct;
use Omnitrade\Model\ProductVariant as RemoteVariant;
use Omnitrade\Model\Stock;
use Omnitrade\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The catalogue kept on a platform, copied here: every product an omnitrade
 * gateway reads (Stripe's Products, a Shopify shop, a WooCommerce site)
 * becomes a Product of the store - its variants Variants -, its vendor a
 * Brand, its key/value attributes the attributes of the same code, its
 * tags Features, its SKU and barcode identifiers. Payment stays wherever
 * the shop pays (omnitrade/stripe), whatever the source of the catalogue.
 *
 * One way only, the platform to the site, and gently:
 *
 *   1. only the fields of marketplace.catalogue.owned_fields are written:
 *      the taxa, the pairings, the age gate, the lots, an image chosen
 *      here - never listed, never touched;
 *   2. a variant whose owned fields hash the same as last time is not
 *      written at all (no updatedAt bump, no cache invalidated);
 *   3. nothing is deleted: a product gone from the platform (or no longer
 *      active) is taken off sale (DISCONTINUED, no stock), its order
 *      history intact, its link kept as orphaned.
 *
 * A first import finds what the shop already sells by SKU and links it
 * rather than making a duplicate.
 */
class PlatformSynchronizer
{
    /** A product carrying this feature (a tag) is never written by the sync. */
    public const UNMANAGED_TAG = 'platform-unmanaged';

    /** @var array<string, int> */
    private array $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'discontinued' => 0, 'stock' => 0];

    /** @var array<string, AbstractAdapter|false> */
    private array $adapters = [];

    /** @param list<string> $ownedFields */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlatformLinkRepository $links,
        private readonly ?Registry $registry = null,
        #[Autowire('%marketplace.catalogue.store%')] private readonly ?string $storeSlug = null,
        #[Autowire('%marketplace.catalogue.owned_fields%')] private readonly array $ownedFields = [],
        #[Autowire('%marketplace.default_currency%')] private readonly string $defaultCurrency = 'EUR',
    ) {
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = array_map(static fn () => 0, $this->stats);
    }

    public function gateway(string $name): GatewayInterface
    {
        if (!$this->registry) {
            throw new \LogicException('glitchr/omnitrade is not installed: no platform to read a catalogue from.');
        }

        return $this->registry->get($name);
    }

    /**
     * Every product of the platform, page by page - only what changed since
     * $since when given. With $full, what the platform no longer has is
     * taken off sale here.
     *
     * @param callable(RemoteProduct, string): void|null $progress told of each product and what became of it
     *
     * @return array<string, int> the counters
     */
    public function sync(string $gatewayName, ?\DateTimeInterface $since = null, bool $full = false, bool $dryRun = false, ?callable $progress = null, ?string $query = null): array
    {
        $gateway = $this->gateway($gatewayName);
        $seen = [];
        $cursor = null;
        do {
            $page = $gateway->fetchProducts($cursor, $since, $query);
            foreach ($page->products as $remote) {
                $outcome = $this->synchronize($gatewayName, $remote, $dryRun);
                $seen[$remote->reference] = true;
                if ($progress) {
                    $progress($remote, $outcome);
                }
            }
            if (!$dryRun) {
                $this->entityManager->flush();
            }
            $cursor = $page->next;
        } while (null !== $cursor);

        if ($full && null === $since && null === $query) {
            foreach ($this->links->findBy(['gateway' => $gatewayName, 'orphaned' => false]) as $link) {
                if (!isset($seen[$link->getRemoteProduct()])) {
                    $this->discontinue($gatewayName, $link->getRemoteProduct(), $dryRun);
                }
            }
            if (!$dryRun) {
                $this->entityManager->flush();
            }
        }

        return $this->stats;
    }

    /**
     * One platform product into the catalogue: its first variant is the
     * Product, the others its Variants.
     *
     * @return string created, updated, unchanged, skipped or discontinued (the first variant's)
     */
    public function synchronize(string $gatewayName, RemoteProduct $remote, bool $dryRun = false): string
    {
        if (!$remote->isActive()) {
            return $this->discontinue($gatewayName, $remote->reference, $dryRun) > 0 ? 'discontinued' : 'skipped';
        }

        $variants = $remote->variants ?: [new RemoteVariant($remote->reference)];
        $principal = null;
        $outcome = 'unchanged';
        foreach (array_values($variants) as $i => $variant) {
            $result = $this->synchronizeVariant($gatewayName, $remote, $variant, 0 === $i ? null : $principal, \count($variants) > 1, $dryRun, $product);
            if (0 === $i) {
                $principal = $product;
                $outcome = $result;
            }
        }

        return $outcome;
    }

    /** The product is gone from the platform: taken off sale, never deleted. */
    public function discontinue(string $gatewayName, string $remoteProduct, bool $dryRun = false): int
    {
        $count = 0;
        foreach ($this->links->findProduct($gatewayName, $remoteProduct) as $link) {
            $product = $link->getProduct();
            if ($link->isOrphaned() || $this->isUnmanaged($product)) {
                continue;
            }
            ++$count;
            if ($dryRun) {
                continue;
            }
            if ($this->owns('availability')) {
                $product->setAvailability(ProductAvailability::DISCONTINUED);
            }
            if ($this->owns('stock')) {
                $product->setStock(0);
            }
            $link->setOrphaned(true);
        }
        $this->stats['discontinued'] += $count;

        return $count;
    }

    /** A stock level the platform counted: by the variant's id there, or its counted item's. */
    public function updateStock(string $gatewayName, Stock $stock): bool
    {
        if (!$this->owns('stock')) {
            return false;
        }
        $link = $this->links->findVariant($gatewayName, $stock->reference)
            ?? (null !== $stock->item ? $this->links->findItem($gatewayName, $stock->item) : null)
            ?? $this->links->findItem($gatewayName, $stock->reference);
        if (!$link || $link->isOrphaned() || $this->isUnmanaged($link->getProduct())) {
            return false;
        }
        $product = $link->getProduct();
        $quantity = $stock->tracked ? $stock->quantity : null;
        $product->setStock($quantity);
        // Stock and availability are two fields here and one idea there:
        // left to disagree, a sold-out product stays buyable.
        if ($this->owns('availability')) {
            $product->setAvailability($stock->available() ? ProductAvailability::INSTOCK : ProductAvailability::OUT_OF_STOCK);
        }
        // The fingerprint no longer says what is stored: the next sync writes it again.
        $link->synced('', $link->getRemoteUpdatedAt());
        ++$this->stats['stock'];

        return true;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /** What a variant's owned fields hash to: the same as last time, nothing is written. */
    public function fingerprint(RemoteProduct $remote, RemoteVariant $variant): string
    {
        $offer = $variant->offer();
        $all = [
            'title' => $remote->title.'|'.$variant->title,
            'description' => $remote->description,
            'slug' => $remote->handle,
            'price' => $offer ? $offer->price->amount.$offer->price->currency : null,
            'stock' => $variant->stock ? ($variant->stock->tracked ? $variant->stock->quantity : 'untracked') : null,
            'availability' => $remote->status.'|'.($offer?->available ? '1' : '0'),
            'identifiers' => $variant->sku.'|'.$variant->barcode,
            'brand' => $remote->brand,
            'attributes' => json_encode($remote->attributes + $variant->attributes),
            'tags' => implode(',', $remote->tags),
        ];
        $owned = array_intersect_key($all, array_flip($this->ownedFields));
        ksort($owned);

        return hash('sha256', (string) json_encode($owned));
    }

    private function synchronizeVariant(string $gatewayName, RemoteProduct $remote, RemoteVariant $variant, ?Product $principal, bool $several, bool $dryRun, ?Product &$product = null): string
    {
        $link = $this->links->findVariant($gatewayName, $variant->reference);
        $product = $link?->getProduct();

        if ($product && $this->isUnmanaged($product)) {
            ++$this->stats['skipped'];

            return 'skipped';
        }

        $fingerprint = $this->fingerprint($remote, $variant);
        if ($link && $product && !$link->isOrphaned() && $link->getFingerprint() === $fingerprint) {
            ++$this->stats['unchanged'];

            return 'unchanged';
        }

        $created = null === $product;
        if ($created) {
            $product = $this->findBySku($variant->sku) ?? $this->create($remote, $variant, $principal);
            $created = null === $product->getId();
        }

        if ($dryRun) {
            ++$this->stats[$created ? 'created' : 'updated'];

            return $created ? 'created' : 'updated';
        }

        $this->apply($product, $remote, $variant, null !== $principal, $several);

        $link ??= new PlatformLink($product, $gatewayName, $remote->reference, $variant->reference);
        $link->setRemoteItem($variant->stock?->item)->setSku($variant->sku)->synced($fingerprint, $remote->updatedAt);
        $this->entityManager->persist($product);
        $this->entityManager->persist($link);

        ++$this->stats[$created ? 'created' : 'updated'];

        return $created ? 'created' : 'updated';
    }

    private function create(RemoteProduct $remote, RemoteVariant $variant, ?Product $principal): Product
    {
        $store = $this->store();
        $price = $variant->price();
        $currency = $store?->getCurrency() ?: ($price?->currency ?? $this->defaultCurrency);
        $amount = $price?->amount ?? 0;

        $product = $principal ? new Variant($principal, $amount, $currency) : new Product(null, $store, $amount, $currency);
        if (!$principal) {
            if ($store) {
                $product->setStore($store);
            }
            $product->setTitle($remote->title);
            if ($remote->handle) {
                $product->setSlug($this->uniqueSlug($remote->handle, $variant->reference, $product));
            }
        } else {
            $principal->addVariant($product);
        }

        return $product;
    }

    private function apply(Product $product, RemoteProduct $remote, RemoteVariant $variant, bool $isVariant, bool $several): void
    {
        if ($this->owns('title')) {
            $product->setTitle($isVariant ? ($variant->title ?? $remote->title) : $remote->title);
        }
        if ($this->owns('description') && !$isVariant && null !== $remote->description) {
            $product->setContent($remote->description);
        }
        if ($this->owns('slug') && !$isVariant && $remote->handle) {
            $product->setSlug($this->uniqueSlug($remote->handle, $variant->reference, $product));
        }
        if ($this->owns('price') && ($price = $variant->price())) {
            // The platform's own currency: a product may sell in another than its store's.
            $product->setCurrency($price->currency);
            $product->setUnitPrice($price->amount);
        }
        if ($this->owns('stock') && $variant->stock) {
            $product->setStock($variant->stock->tracked ? $variant->stock->quantity : null);
        }
        if ($this->owns('availability')) {
            $offer = $variant->offer();
            $available = (null === $offer || $offer->available) && (null === $variant->stock || $variant->stock->available());
            $product->setAvailability($available ? ProductAvailability::INSTOCK : ProductAvailability::OUT_OF_STOCK);
        }
        if ($this->owns('identifiers')) {
            $this->applyIdentifiers($product, $variant->sku, $variant->barcode);
        }
        if ($this->owns('brand') && !$isVariant && $remote->brand) {
            $product->setBrand($this->brand($remote->brand));
        }
        if ($this->owns('attributes')) {
            $this->applyAttributes($product, $isVariant ? $variant->attributes + $variant->options : $remote->attributes + ($several ? [] : $variant->options));
        }
        if ($this->owns('tags') && !$isVariant) {
            $this->applyTags($product, $remote->tags);
        }
    }

    /** Only the attributes whose code is an adapter's (an AttributeSet field): the rest of the platform's keys are left there. */
    private function applyAttributes(Product $product, array $values): void
    {
        foreach ($values as $key => $value) {
            $adapter = $this->adapter((string) $key);
            if (!$adapter) {
                continue;
            }
            $value = \is_array($value) ? implode(', ', $value) : (string) $value;
            $attribute = $product->getAttribute($adapter->getCode());
            if ($attribute) {
                $attribute->setValue($value);
                continue;
            }
            $attribute = new Attribute($adapter, $value);
            $attribute->setProduct($product);
            $product->addAttribute($attribute);
        }
    }

    private function adapter(string $key): ?AbstractAdapter
    {
        // "custom.appellation" (a Shopify metafield), "pa_appellation" (a
        // WooCommerce attribute), "Appellation": the code is its last word.
        $code = strtolower(preg_replace('/^(?:.*\.|pa_)/', '', trim($key)));
        $code = trim((string) preg_replace('/[^a-z0-9_]+/', '_', $code), '_');
        if (!\array_key_exists($code, $this->adapters)) {
            $this->adapters[$code] = '' === $code ? false : ($this->entityManager->getRepository(AbstractAdapter::class)->findOneBy(['code' => $code]) ?? false);
        }

        return $this->adapters[$code] ?: null;
    }

    private function brand(string $name): Brand
    {
        $repository = $this->entityManager->getRepository(Brand::class);
        $brand = method_exists($repository, 'findOneByName') ? $repository->findOneByName($name) : null;
        if (!$brand) {
            $brand = new Brand($name);
            $this->entityManager->persist($brand);
        }

        return $brand;
    }

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
        return null === $this->storeSlug ? null : $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => $this->storeSlug]);
    }

    /** A product already sold here under this SKU: linked, not duplicated. */
    private function findBySku(?string $sku): ?Product
    {
        if (null === $sku || '' === trim($sku)) {
            return null;
        }
        $barcode = $this->entityManager->getRepository(BarcodeAttribute::class)->findOneBy(['value' => Barcode::SKU.' '.str_replace(' ', '', $sku)]);

        return $barcode?->getIdentifier()?->getProduct();
    }

    /** Slugs are unique across threads: a handle taken elsewhere gets the variant's id. */
    private function uniqueSlug(string $handle, string $reference, Product $product): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($handle)), '-');
        $existing = $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
        if (null === $existing || $existing === $product) {
            return $slug;
        }

        return $slug.'-'.substr(hash('crc32b', $reference), 0, 6);
    }

    private function applyIdentifiers(Product $product, ?string $sku, ?string $code): void
    {
        $wanted = array_filter([Barcode::SKU => $sku, $this->barcodeStandard($code) => $code]);
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
            if ($existing = $identifier->getBarcode($standard)) {
                $existing->setValue($value);
                continue;
            }
            $adapter = new BarcodeAdapter();
            $adapter->setStandard($standard);
            $identifier->addBarcode(new BarcodeAttribute($adapter, $value));
        }
    }

    /** EAN at 13 digits, UPC at 12, GTIN for anything else. */
    private function barcodeStandard(?string $code): string
    {
        return match (\strlen((string) preg_replace('/\D/', '', (string) $code))) {
            13 => Barcode::EAN,
            12 => Barcode::UPC,
            default => Barcode::GTIN,
        };
    }

    /** @param list<string> $tags */
    private function applyTags(Product $product, array $tags): void
    {
        $repository = $this->entityManager->getRepository(Feature::class);
        foreach ($tags as $label) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($label))), '-');
            if ('' === $slug || self::UNMANAGED_TAG === $slug) {
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
}
