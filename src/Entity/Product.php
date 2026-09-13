<?php

namespace Base\Market\Entity;

use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Product\Attribute;
use Base\Market\Entity\Product\Attribute\Hyperlink;
use Base\Market\Entity\Product\Feature;
use Base\Market\Entity\Product\Identifier;
use Base\Market\Entity\Product\Image;
use Base\Market\Entity\Sales\Channel;
use Base\Market\Model\MerchantInterface;
use Base\Market\Enum\Barcode;
use Base\Market\Enum\ProductAvailability;
use Base\Market\Model\ShippingUnitInterface;
use Base\Market\Repository\ProductRepository;
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

        $routeName = 'app_product';
        $routeParameters = array_merge($routeParameters, [
            'store' => $this->getStore()->getSlug(),
            'product' => $this->getSlug(),
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
        $this->currency = $currency ?? $this->getParameterBag('market.default_currency') ?? 'USD';

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
    #[\Base\Database\Attribute\Cascade(value: ['persist', 'remove'])]
    #[\Base\Database\Attribute\OrphanRemoval(value: true)]
    protected $variants;

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

    public function getPriceRange(): array
    {
        return [$this->getPrice(), $this->getPrice()];
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
     * @param $availability
     * @return $this
     */
    /**
     * @param $availability
     * @return $this
     */
    public function setAvailability($availability): self
    {
        $this->availability = $availability;

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Attribute::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[\Base\Database\Attribute\OrderColumn]
    protected $attributes;

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
    #[\Base\Database\Attribute\OrderColumn]
    protected $exports;

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
    #[\Base\Database\Attribute\OrderColumn]
    protected $images;

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
    #[\Base\Database\Attribute\OrderColumn]
    protected $imageMarketplaces;

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
     * @param $imageMarketplaces
     * @return $this
     */
    /**
     * @param $imageMarketplaces
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
}
