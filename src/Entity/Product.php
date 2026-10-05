<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product\Association;
use Base\Marketplace\Entity\Product\Attribute;
use Base\Marketplace\Entity\Product\Attribute\Hyperlink;
use Base\Marketplace\Entity\Product\Feature;
use Base\Marketplace\Entity\Product\Identifier;
use Base\Marketplace\Entity\Product\Image;
use Base\Marketplace\Entity\Product\OptionGroup;
use Base\Marketplace\Entity\Sales\Channel;
use Base\Marketplace\Model\MerchantInterface;
use Base\Marketplace\Enum\AssociationType;
use Base\Marketplace\Enum\Barcode;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Marketplace\Model\ShippingUnitInterface;
use Base\Marketplace\Repository\ProductRepository;
use Base\Database\Attribute\Hierarchify;
use Base\Database\Attribute\Uploader;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\Cascade;
use Base\Database\Attribute\Alias;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Attribute\OrderColumn;
use Base\Database\Attribute\OrphanRemoval;
use Base\Database\Entity\Extension\TranslatableTrait;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Entity\Layout\Attribute\Adapter\ColorAdapter;
use Base\Entity\Layout\Attribute\Adapter\HyperpatternAdapter;
use Base\Entity\Layout\Attribute\Adapter\ScalarAdapter;
use Base\Entity\Layout\ImageCrop;
use Base\Entity\Thread;
use Base\Service\Localizer;
use Base\Service\Model\AutocompleteInterface;
use Base\Service\Model\LinkableInterface;
use Base\Traits\BaseTrait;
use Base\Traits\CacheableTrait;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
#[AssertBase\UniqueEntity(fields: ['identifiers.barcodes.value'], groups: ['new', 'edit'])]
#[\Base\Database\Attribute\Hierarchify(hierarchy: ['store', 'products'], separator: '/')]
class Product extends Thread implements \Base\Database\Entity\Extension\TranslatableInterface, AutocompleteInterface, LinkableInterface, ShippingUnitInterface
{
    use BaseTrait;
    use \Base\Database\Entity\Extension\TranslatableTrait;
    use CacheableTrait;

    public function __toKey(mixed ...$variadic): string
    {
        $variadic[] = $this->getId();
        $variadic[] = $this->getUpdatedAt();
        $variadic[] = array_map(fn($v) => $v->__toKey(''), $this->getVariants()->toArray());
        return $this->__toDefaultKey(...$variadic);
    }

    public function __typesense(): ?string
    {
        return $this->__autocomplete();
    }

    public const INHERITS_FROM = true;

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-tag'];
    }

    public function __autocomplete(): string
    {
        return $this->getTwig()->render('entity/marketplace/product.html.twig', ['product' => $this]);
    }

    public function __autocompleteData(): array
    {
        return [];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        if (null == $this->getStore()) {
            return null;
        }

        $routeName = 'marketplace_product';
        $routeParameters = array_merge($routeParameters, [
            'store' => $this->getStore()->getSlug(),
            'slug' => $this->getSlug(),
        ]);

        return $this->getRouter()->generate($routeName, $routeParameters, $referenceType);
    }

    /**
     * @return string|null
     */
    /**
     * Which class this product is ROUTED as, which is not always the class it
     * is.
     *
     * A product whose route is its own (a wallpaper, a book) answers with
     * itself and needs nothing here. One that is a satellite of another - a
     * free sample, a bundle, a made-to-measure cut - overrides this to name
     * the class whose route it should appear under, so it does not need a
     * route of its own.
     *
     * Exists so Review (and anything else building a product route) can stop
     * switching on concrete product classes. That switch is what tied the
     * marketplace to one shop's catalogue and kept it out of a bundle.
     *
     * @return class-string
     */
    public function getRouteIdentifierClass(): string
    {
        return static::class;
    }

    public function __toString()
    {
        $reference = $this->getReference();

        return $this->getTitle() ?? ($reference ? ($this->getTranslator()->transEntity(self::class) . ' #' . $reference) : get_class($this));
    }

    public function __construct(?MerchantInterface $merchant = null, ?Store $store = null, int $unitPrice = 0, ?string $currency = null)
    {
        parent::__construct($merchant, $store);

        $this->availability = ProductAvailability::INSTOCK;
        $this->stock = null;

        $this->unitPrice = $unitPrice;
        $this->currency = $currency ?? $this->getParameterBag('marketplace.default_currency') ?? 'USD';

        $this->rating = 0;

        $this->variants = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->attributes = new ArrayCollection();
        $this->exports = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->features = new ArrayCollection();
        $this->orderItems = new ArrayCollection();
        $this->identifiers = new ArrayCollection();
        $this->channels = new ArrayCollection();
        $this->associations = new ArrayCollection();
        $this->optionGroups = new ArrayCollection();
    }

    public function getHeadline(?string $locale = null, int $inheritanceDepthIfNotSet = 0): ?string
    {
        return parent::getHeadline($locale, $inheritanceDepthIfNotSet)
            ?? implode('-', array_filter([
                implode('-', $this->translate($locale)->getKeywords()),
                $this->translate($locale)->getTitle(),
                $this->getOwner() instanceof MerchantInterface ? $this->getOwner()->getCompanyName() : null,
            ]));
    }

    public function getCategories(): ?array
    {
        return $this->getTaxa()->map(fn($t) => $t->getLabel())->toArray();
    }

    public function getBrands(): ?array
    {
        $brand = [];
        foreach ($this->getOwners() as $owner) {
            $brand[] = $owner instanceof MerchantInterface ? $owner->getCompanyName() : null;
        }

        return $brand;
    }

    public function getReference(int $chrLength = 3, int $numLength = 4): ?string
    {
        if (null === $this->getId()) {
            return null;
        }

        $title = $this->getTitle(Localizer::getDefaultLocale()) ?? '';
        $title = preg_replace('/[^a-zA-Z ]/', '', $title);
        $title = preg_replace('/[aAeEiIoOuU0-9]/', '', $title);

        $words = array_filter(explode(' ', $title));
        $nwords = count($words);

        if ($nwords > 1) {
            $chr = implode('', array_map(fn($w) => $w[0] ?? '', $words));
        } elseif (1 == $nwords) {
            $chr = first($words);
        } else {
            $chr = 'UNK';
        }

        $reference = substr($chr, 0, $chrLength) . digits($this->getId(), $numLength);

        return strtoupper($reference);
    }

    #[Alias(column: 'parent')]
    protected $store;

    /**
     * @param Store|null $store
     * @return Product
     */
    public function setStore(?Store $store)
    {
        return $this->setParent($store);
    }

    public function getStore(): ?Store
    {
        return $this->getParent();
    }

    #[Alias(column: 'children')]
    #[\Base\Database\Attribute\OrphanRemoval(value: true)]
    protected $variants;

    /**
     * How many of this product one order may hold; null leaves it to the
     * shop's cart_max_quantity. A subclass selling something owned once (an
     * avatar item, a licence) returns 1.
     */
    /**
     * Whether the product travels by post. Physical goods do; a subclass
     * selling something that lives online (an avatar item, in-game coins)
     * returns false and checkout asks for no address.
     */
    /** Something to send: goods. A plan or a pack of credits is delivered as a right, with no address asked. */
    public function isShippable(): bool
    {
        return \Base\Marketplace\Enum\ProductKind::GOODS === $this->getKind();
    }

    public function getMaxQuantity(): ?int
    {
        return null;
    }

    /**
     * @return false
     */
    public static function isVariant()
    {
        return false;
    }

    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(Product $variant): self
    {
        return $this->addChild($variant);
    }

    public function removeVariant(Product $variant): self
    {
        return $this->removeChild($variant);
    }

    public function removeAllVariants(): self
    {
        foreach ($this->getVariants() as $variant) {
            $this->removeVariant($variant);
        }

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $rating;

    protected function getRating(): ?int
    {
        return $this->rating;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    protected function computeRating()
    {
        $approvedReviews = 0;
        foreach ($this->reviews as $review) {
            if ($review->isApproved()) { // Only consider approved reviewed
                $this->rating += $review->getRating();
                ++$approvedReviews;
            }
        }

        if ($approvedReviews > 0) {
            $this->rating /= $approvedReviews;
        }

        return $this;
    }

    #[ORM\Column(type: 'integer')]
    protected $unitPrice;

    /**
     * The lowest and the highest price it sells at: its variants' still for
     * sale when it has some (a banner by the square metre, a print by the
     * hundred), its own otherwise. Smallest unit, before VAT.
     *
     * @return array{0: int, 1: int}
     */
    public function getPriceRange(): array
    {
        $prices = [];
        foreach ($this->getVariants() ?? [] as $variant) {
            if ($variant instanceof self && $variant->isForSell() && null !== $variant->getUnitPrice()) {
                $prices[] = (int) $variant->getUnitPrice();
            }
        }
        $prices = $prices ?: [(int) $this->getPrice()];

        return [min($prices), max($prices)];
    }

    /**
     * A starting price typed by hand, for what is priced on request (a sign
     * "from 300 €"): shown as such, never charged - the unit price is.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected $priceFrom = null;

    public function getPriceFrom(): ?int
    {
        return $this->priceFrom;
    }

    public function setPriceFrom(?int $priceFrom): self
    {
        $this->priceFrom = null === $priceFrom ? null : max(0, $priceFrom);

        return $this;
    }

    /** The price a list shows: the one typed (priceFrom), else the lowest of its range. */
    public function getStartingPrice(): int
    {
        return $this->priceFrom ?? $this->getPriceRange()[0];
    }

    /** Whether that price is a "from": typed as one, or the lowest of several. */
    public function isPricedFrom(): bool
    {
        [$lowest, $highest] = $this->getPriceRange();

        return null !== $this->priceFrom || $lowest !== $highest;
    }

    /**
     * @return int|null
     */
    public function getPrice()
    {
        return $this->getUnitPrice();
    }

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): self
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 3)]
    protected $currency;

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getColors(): array
    {
        return $this->getAttributes('product-color', ColorAdapter::class)->Map(fn($a) => $a->getValue())->toArray();
    }

    public function getWeight(): ?int
    {
        return $this->getAttribute('product-weight', ScalarAdapter::class) ? $this->getAttribute('product-weight', ScalarAdapter::class)->getValue() : null;
    }

    public function getWeightUnit(): ?string
    {
        return $this->getAttribute('product-weight', ScalarAdapter::class) ? $this->getAttribute('product-weight', ScalarAdapter::class)->getUnit() : null;
    }

    public function getWidth(): ?int
    {
        return $this->getAttribute('product-width', ScalarAdapter::class) ? $this->getAttribute('product-width', ScalarAdapter::class)->getValue() : null;
    }

    public function getWidthUnit(): ?string
    {
        return $this->getAttribute('product-width', ScalarAdapter::class) ? $this->getAttribute('product-width', ScalarAdapter::class)->getUnit() : null;
    }

    public function getHeight(): ?int
    {
        return $this->getAttribute('product-height', ScalarAdapter::class) ? $this->getAttribute('product-height', ScalarAdapter::class)->getValue() : null;
    }

    public function getHeightUnit(): ?string
    {
        return $this->getAttribute('product-height', ScalarAdapter::class) ? $this->getAttribute('product-height', ScalarAdapter::class)->getUnit() : null;
    }

    public function getLength(): ?int
    {
        return $this->getAttribute('product-length', ScalarAdapter::class) ? $this->getAttribute('product-length', ScalarAdapter::class)->getValue() : null;
    }

    public function getLengthUnit(): ?string
    {
        return $this->getAttribute('product-length', ScalarAdapter::class) ? $this->getAttribute('product-length', ScalarAdapter::class)->getUnit() : null;
    }

    public function getDimensions(): array
    {
        return array_filter([$this->getLength(), $this->getWidth(), $this->getHeight()]);
    }

    public function getShippingUnits(): float
    {
        return 1;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $stock;

    public function getStock(): ?int
    {
        return $this->stock;
    }

    public function setStock(?int $stock): self
    {
        $this->stock = $stock;

        return $this;
    }

    #[ORM\Column(type: 'product_availability')]
    protected $availability;

    /**
     * @return bool
     */
    public function isForSell()
    {
        return in_array($this->getAvailability(), [
            ProductAvailability::PREORDER,
            ProductAvailability::PRESALE,
            ProductAvailability::INSTOCK,
            ProductAvailability::LIMITED,
            ProductAvailability::BACKORDER,
            ProductAvailability::ONLINE_ONLY,
        ]);
    }

    /**
     * @return string
     */
    public function getAvailability()
    {
        return $this->availability;
    }

    /**
     * An availability is never nothing (the column refuses null): given
     * none, the product keeps the one it had - in stock for a new one. The
     * back office's select is filled by a script; a form sent without it
     * carries no value, and the insertion stopped on "Column 'availability'
     * cannot be null".
     *
     * @return $this
     */
    public function setAvailability($availability): self
    {
        if (null !== $availability && '' !== $availability) {
            $this->availability = $availability;
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Attribute::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[\Base\Database\Attribute\OrderColumn(orderBy: 'attributePositions')]
    protected $attributes;
    protected $attributePositions;

    public function getAttributes(?string $code = null, ?string $abstractAttributeClassName = null): Collection
    {
        if (null != $code || null != $abstractAttributeClassName) {
            return $this->attributes->filter(function ($a) use ($code, $abstractAttributeClassName) {
                $adapter = $a->getAdapter();
                if (null !== $abstractAttributeClassName && !$adapter instanceof $abstractAttributeClassName) {
                    return false;
                }
                if (null != $code && $adapter->getCode() != $code) {
                    return false;
                }

                return true;
            });
        }

        return $this->attributes;
    }

    public function getAttribute(string $code, ?string $abstractAttributeClassName = null): ?Attribute
    {
        return $this->getAttributes($code, $abstractAttributeClassName)?->first() ?: null;
    }

    public function addAttribute(Attribute $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes[] = $attribute;
            $attribute->setProduct($this);
        }

        return $this;
    }

    public function removeAttribute(Attribute $attribute): self
    {
        if ($this->attributes->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getProduct() === $this) {
                $attribute->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Hyperlink::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[\Base\Database\Attribute\OrderColumn(orderBy: 'exportPositions')]
    protected $exports;
    protected $exportPositions;

    public function getExports(?string $code = null): Collection
    {
        if (null != $code) {
            return $this->exports->filter(function ($a) use ($code) {
                $adapter = $a->getAdapter();
                if (!$adapter instanceof HyperpatternAdapter) {
                    return false;
                }
                if (null != $code && $adapter->getCode() != $code) {
                    return false;
                }

                return true;
            });
        }

        return $this->exports;
    }

    public function getExport(string $code): mixed
    {
        $exports = $this->getExports($code);

        return $exports->first() ?: null;
    }

    public function addExport(Hyperlink $attribute): self
    {
        if (!$this->exports->contains($attribute)) {
            $this->exports[] = $attribute;
            $attribute->setProduct($this);
        }

        return $this;
    }

    public function removeExport(Hyperlink $attribute): self
    {
        if ($this->exports->removeElement($attribute)) {
            // set the owning side to null (unless already changed)
            if ($attribute->getProduct() === $this) {
                $attribute->setProduct(null);
            }
        }

        return $this;
    }

    #[Alias(column: 'tags')]
    protected $features;

    public function getFeatures(): Collection
    {
        return $this->getTags();
    }

    public function addFeature(Feature $feature): self
    {
        return $this->addTag($feature);
    }

    public function removeFeature(Feature $feature): self
    {
        return $this->removeTag($feature);
    }

    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'product')]
    protected $orderItems;

    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }

    public function addOrderItem(OrderItem $orderItem): self
    {
        if (!$this->orderItems->contains($orderItem)) {
            $this->orderItems[] = $orderItem;
            $orderItem->setProduct($this);
        }

        return $this;
    }

    public function removeOrderItem(OrderItem $orderItem): self
    {
        if ($this->orderItems->removeElement($orderItem)) {
            // set the owning side to null (unless already changed)
            if ($orderItem->getProduct() === $this) {
                $orderItem->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Identifier::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $identifiers;

    public function getIdentifiers(): Collection
    {
        return $this->identifiers;
    }

    public function addIdentifier(Identifier $identifier): self
    {
        if (!$this->identifiers->contains($identifier)) {
            $this->identifiers[] = $identifier;
            $identifier->setProduct($this);
        }

        return $this;
    }

    /**
     * @param int $i
     * @return mixed|null
     */
    public function getAuthor(int $i = 0)
    {
        return $this->getOwner($i);
    }

    /**
     * The owners that count as authors of this product.
     *
     * Not every owner is one - a product is owned by the merchant selling it
     * as well as by whoever created it - so this filters, and getAuthorClass()
     * below decides on what. It used to filter on `instanceof Artist`, a class
     * belonging to one shop, which is what kept Product out of a bundle.
     */
    public function getAuthors(): Collection
    {
        return $this->getOwnersOf($this->getAuthorClass());
    }

    /**
     * Which kind of owner authored this product.
     *
     * Everyone by default, because a marketplace with no separate notion of a
     * creator should not silently return nothing here. A shop that does have
     * one - wallpapers have Artists - narrows it by overriding this.
     *
     * @return class-string
     */
    protected function getAuthorClass(): string
    {
        return \Base\Entity\User::class;
    }

    /**
     * @param class-string $class
     */
    public function getOwnersOf(string $class): Collection
    {
        return $this->getOwners()->filter(fn ($owner) => $owner instanceof $class);
    }

    public function getEAN(): ?string
    {
        $barcodes = array_filter($this->getBarcodes(Barcode::EAN)->toArray());

        return $barcodes ? implode(', ', $barcodes) : null;
    }

    public function getBarcodes(string $std): Collection
    {
        return $this->getIdentifiers()->map(function ($id) use ($std) {
            $barcode = $id->getBarcode($std);

            return $barcode ? $barcode->getValue() : null;
        });
    }

    public function removeIdentifier(Identifier $identifier): self
    {
        if ($this->identifiers->removeElement($identifier)) {
            // set the owning side to null (unless already changed)
            if ($identifier->getProduct() === $this) {
                $identifier->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Image::class, mappedBy: 'product', orphanRemoval: true, cascade: ['persist', 'remove'])]
    #[\Base\Database\Attribute\OrderColumn(orderBy: 'imagePositions')]
    protected $images;
    protected $imagePositions;

    public function getImage(?int $i = null): ?Image
    {
        return null === $i ? ($this->images->first() ?: null) : $this->images->get($i);
    }

    public function getImages(): Collection
    {
        return $this->images;
    }

    public function getImageCrop(string $slug, ?int $i = null): ?ImageCrop
    {
        if (null === $i) {
            foreach ($this->images as $image) {
                if ($crop = $image->getCrop($slug)) {
                    return $crop;
                }
            }

            return null;
        }

        return $this->getImage($i)?->getCrop($slug);
    }

    public function addImage(Image $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images[] = $image;
            $image->setProduct($this);
        }

        return $this;
    }

    public function removeImage(Image $image): self
    {
        if ($this->images->removeElement($image)) {
            // set the owning side to null (unless already changed)
            if ($image->getProduct() === $this) {
                $image->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\Column(type: 'array', nullable: true)]
    #[\Base\Database\Attribute\Uploader(mime_types: ['image/*'])]
    #[AssertBase\File(mime_types: ['image/*'], groups: ['new', 'edit'], max_size: '10MB')]
    #[\Base\Database\Attribute\OrderColumn(orderBy: 'imageMarketplacePositions')]
    protected $imageMarketplaces;
    protected $imageMarketplacePositions;

    /**
     * @return array|mixed|File
     */
    public function getImageMarketplaces()
    {
        return \Base\Database\Attribute\Uploader::getPublic($this, 'imageMarketplaces') ?? [];
    }

    /**
     * @return array|mixed|File
     */
    public function getImageMarketplaceFiles()
    {
        return \Base\Database\Attribute\Uploader::get($this, 'imageMarketplaces') ?? [];
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function setImageMarketplaces($imageMarketplaces)
    {
        $this->imageMarketplaces = $imageMarketplaces;

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'product', cascade: ['persist'])]
    protected $reviews;

    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(Review $review): self
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews[] = $review;
            $review->setProduct($this);
        }

        return $this;
    }

    public function removeReview(Review $review): self
    {
        if ($this->reviews->removeElement($review)) {
            // set the owning side to null (unless already changed)
            if ($review->getProduct() === $this) {
                $review->setProduct(null);
            }
        }

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Channel::class, inversedBy: 'products')]
    protected $channels;

    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(Channel $channel): self
    {
        if (!$this->channels->contains($channel)) {
            $this->channels[] = $channel;
        }

        return $this;
    }

    public function removeChannel(Channel $channel): self
    {
        $this->channels->removeElement($channel);

        return $this;
    }

    /**
     * Who makes it: the estate, the house, the maker (Brand). Shopify's
     * vendor, WooCommerce's brand. A variant has its principal's.
     */
    #[ORM\ManyToOne(targetEntity: Brand::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected $brand;

    public function getBrand(): ?Brand
    {
        return $this->brand ?? ($this->isVariant() && method_exists($this, 'getPrincipal') ? $this->getPrincipal()?->getBrand() : null);
    }

    public function setBrand(?Brand $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    /**
     * Sold by the lot: a cart holds a multiple of it (a case of 6, of 12).
     * null: by the unit - or, for a variant, as its principal.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected $packSize;

    public function getPackSize(): int
    {
        $own = $this->packSize;
        if (null === $own && $this->isVariant() && method_exists($this, 'getPrincipal')) {
            return $this->getPrincipal()?->getPackSize() ?? 1;
        }

        return max(1, (int) ($own ?? 1));
    }

    public function getOwnPackSize(): ?int
    {
        return $this->packSize;
    }

    public function setPackSize(?int $packSize): self
    {
        $this->packSize = null === $packSize ? null : max(1, $packSize);

        return $this;
    }

    /**
     * The fewest one order may hold (a minimum of 6 bottles across cases of
     * 6, of 12...); rounded up to the lot. null: one lot - or, for a variant,
     * as its principal.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected $minimumQuantity;

    public function getMinimumQuantity(): int
    {
        $own = $this->minimumQuantity;
        if (null === $own && $this->isVariant() && method_exists($this, 'getPrincipal')) {
            return $this->getPrincipal()?->getMinimumQuantity() ?? $this->getPackSize();
        }
        $pack = $this->getPackSize();

        return (int) (ceil(max(1, (int) ($own ?? $pack)) / $pack) * $pack);
    }

    public function getOwnMinimumQuantity(): ?int
    {
        return $this->minimumQuantity;
    }

    public function setMinimumQuantity(?int $minimumQuantity): self
    {
        $this->minimumQuantity = null === $minimumQuantity ? null : max(1, $minimumQuantity);

        return $this;
    }

    /**
     * A quantity a cart may hold of it: at least the minimum, a whole number
     * of lots, at most $max (the shop's cart_max_quantity, the stock) rounded
     * down to the lot. 0 when not even the minimum fits under $max.
     */
    public function boundQuantity(int $wanted, ?int $max = null): int
    {
        $pack = $this->getPackSize();
        $quantity = (int) (ceil(max($wanted, $this->getMinimumQuantity()) / $pack) * $pack);
        if (null !== $max) {
            $ceiling = intdiv(max(0, $max), $pack) * $pack;
            if ($ceiling < $this->getMinimumQuantity()) {
                return 0;
            }
            $quantity = min($quantity, $ceiling);
        }

        return $quantity;
    }

    /**
     * What it is once paid (Enum\ProductKind): goods, a plan - a right of
     * access, bought once or by subscription -, or a pack of credits. Kept as
     * its value; a variant without one is as its principal.
     */
    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'goods'])]
    protected $kind = 'goods';

    public function getKind(): \Base\Marketplace\Enum\ProductKind
    {
        $kind = \Base\Marketplace\Enum\ProductKind::tryFrom((string) $this->kind) ?? \Base\Marketplace\Enum\ProductKind::GOODS;
        if (\Base\Marketplace\Enum\ProductKind::GOODS === $kind && $this->isVariant() && method_exists($this, 'getPrincipal') && $this->getPrincipal()) {
            return $this->getPrincipal()->getKind();
        }

        return $kind;
    }

    public function setKind(\Base\Marketplace\Enum\ProductKind|string $kind): self
    {
        $this->kind = $kind instanceof \Base\Marketplace\Enum\ProductKind ? $kind->value : (\Base\Marketplace\Enum\ProductKind::from($kind))->value;

        return $this;
    }

    public function isPlan(): bool
    {
        return \Base\Marketplace\Enum\ProductKind::PLAN === $this->getKind();
    }

    public function isCreditPack(): bool
    {
        return \Base\Marketplace\Enum\ProductKind::CREDIT_PACK === $this->getKind();
    }

    /**
     * A plan's or a credit pack's terms (Model\PlanTerms, as an array): how it
     * is billed, what it grants, the credits it gives. A variant's own terms
     * are laid over its principal's (the pass for 150 guests changes "guests").
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected $plan = null;

    public function getPlan(): ?array
    {
        return $this->plan;
    }

    public function setPlan(\Base\Marketplace\Model\PlanTerms|array|null $plan): self
    {
        $this->plan = $plan instanceof \Base\Marketplace\Model\PlanTerms ? $plan->toArray() : $plan;

        return $this;
    }

    public function getPlanTerms(): \Base\Marketplace\Model\PlanTerms
    {
        $own = $this->plan ?? [];
        if ($this->isVariant() && method_exists($this, 'getPrincipal') && $this->getPrincipal()) {
            $base = $this->getPrincipal()->getPlan() ?? [];
            $own = array_replace($base, $own, [
                'grants' => array_replace((array) ($base['grants'] ?? []), (array) ($own['grants'] ?? [])),
                'credits' => array_replace((array) ($base['credits'] ?? []), (array) ($own['credits'] ?? [])),
            ]);
        }

        return \Base\Marketplace\Model\PlanTerms::fromArray($own);
    }

    /**
     * Made to order by a supplier (Supply\SupplierInterface: "gelato",
     * "offline", an application's own): once paid, Service\Supply hands the
     * line to it. null: the shop sends it itself - or, for a variant, as its
     * principal.
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected $supplier = null;

    public function getSupplier(): ?string
    {
        if (null === $this->supplier && $this->isVariant() && method_exists($this, 'getPrincipal')) {
            return $this->getPrincipal()?->getSupplier();
        }

        return $this->supplier;
    }

    public function setSupplier(?string $supplier): self
    {
        $this->supplier = '' === $supplier ? null : $supplier;

        return $this;
    }

    /** What the supplier calls it: Gelato's productUid, a workshop's own reference. */
    #[ORM\Column(type: 'string', length: 190, nullable: true)]
    protected $supplierReference = null;

    public function getSupplierReference(): ?string
    {
        if (null === $this->supplierReference && $this->isVariant() && method_exists($this, 'getPrincipal')) {
            return $this->getPrincipal()?->getSupplierReference();
        }

        return $this->supplierReference;
    }

    public function setSupplierReference(?string $supplierReference): self
    {
        $this->supplierReference = '' === $supplierReference ? null : $supplierReference;

        return $this;
    }

    /**
     * Sold to adults only (wine, spirits, sake): the age gate stands before
     * its page (Service\AgeGate). Its taxa may say so for it.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected $ageRestricted = false;

    public function isAgeRestricted(): bool
    {
        if ($this->ageRestricted) {
            return true;
        }
        if ($this->isVariant() && method_exists($this, 'getPrincipal') && $this->getPrincipal()?->isAgeRestricted()) {
            return true;
        }
        foreach ($this->getTaxa() as $taxon) {
            if ($taxon instanceof Product\Taxon && $taxon->isAgeRestricted()) {
                return true;
            }
        }

        return false;
    }

    public function getAgeRestricted(): bool
    {
        return (bool) $this->ageRestricted;
    }

    public function setAgeRestricted(bool $ageRestricted): self
    {
        $this->ageRestricted = $ageRestricted;

        return $this;
    }

    /** @var Collection<int, Association> what its page leads to: pairings, cross-sells, upsells */
    #[ORM\OneToMany(targetEntity: Association::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected $associations;

    /** @return Collection<int, Association> */
    public function getAssociations(?AssociationType $type = null): Collection
    {
        $associations = $this->associations ?? new ArrayCollection();

        return null === $type ? $associations : $associations->filter(fn (Association $a) => $a->getType() === $type);
    }

    public function addAssociation(Association $association): self
    {
        $this->associations ??= new ArrayCollection();
        if (!$this->associations->contains($association)) {
            $association->setProduct($this);
            $association->setPosition($this->associations->count());
            $this->associations->add($association);
        }

        return $this;
    }

    public function removeAssociation(Association $association): self
    {
        $this->associations?->removeElement($association);

        return $this;
    }

    /** A pairing, a cross-sell or an upsell to another product, with its note by language. */
    public function associate(Product $target, AssociationType $type = AssociationType::PAIRING, array $notes = []): Association
    {
        foreach ($this->getAssociations($type) as $association) {
            if ($association->getTarget() === $target) {
                return $association->setNotes($notes ?: $association->getNotes());
            }
        }
        $this->addAssociation($association = new Association($this, $target, $type, $notes));

        return $association;
    }

    /** @var Collection<int, OptionGroup> what the buyer chooses on it: a cooking, extras, a finish */
    #[ORM\OneToMany(targetEntity: OptionGroup::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    protected $optionGroups;

    /** @return Collection<int, OptionGroup> its own; a variant without any offers its principal's */
    public function getOptionGroups(): Collection
    {
        $groups = $this->optionGroups ?? new ArrayCollection();
        if ($groups->isEmpty() && $this->isVariant() && method_exists($this, 'getPrincipal') && $this->getPrincipal()) {
            return $this->getPrincipal()->getOptionGroups();
        }

        return $groups;
    }

    public function hasOptions(): bool
    {
        return !$this->getOptionGroups()->isEmpty();
    }

    public function addOptionGroup(OptionGroup $group): self
    {
        $this->optionGroups ??= new ArrayCollection();
        if (!$this->optionGroups->contains($group)) {
            $group->setProduct($this);
            if (0 === $group->getPosition()) {
                $group->setPosition($this->optionGroups->count());
            }
            $this->optionGroups->add($group);
        }

        return $this;
    }

    public function removeOptionGroup(OptionGroup $group): self
    {
        $this->optionGroups?->removeElement($group);

        return $this;
    }

    /**
     * Its attribute of this code (an AttributeSet's field), resolved in a
     * language: "Margaux", 13.5, ["Merlot", "Cabernet sauvignon"]. A variant
     * without it reads its principal's.
     */
    public function getAttributeValue(string $code, ?string $locale = null): mixed
    {
        $attribute = $this->getAttribute($code);
        if (null === $attribute && $this->isVariant() && method_exists($this, 'getPrincipal')) {
            return $this->getPrincipal()?->getAttributeValue($code, $locale);
        }

        $value = $attribute?->resolve($locale);
        // Not written in this language: the default one's (a grape's name is the same in Japanese).
        if (null !== $attribute && null !== $locale && (null === $value || '' === $value || [] === $value)) {
            // By its name: glitchr/omnibase's translate($locale) answers that language alone - its own
            // fallback is for the page's language, asked without a locale.
            $value = $attribute->resolve(Localizer::getDefaultLocale());
        }

        return $value;
    }
}
