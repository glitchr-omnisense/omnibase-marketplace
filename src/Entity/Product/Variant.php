<?php

namespace Base\Market\Entity\Product;

use Base\Market\Entity\Product;
use Base\Market\Entity\Store;
use Base\Market\Repository\Product\VariantRepository;
use Base\Database\Attribute\Hierarchify;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Entity\Extension\TranslatableTrait;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Entity\Layout\ImageCrop;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: VariantRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
#[\Base\Database\Attribute\Hierarchify(hierarchy: ['store', 'products', 'variants'], separator: '/')]
class Variant extends Product implements \Base\Database\Entity\Extension\TranslatableInterface
{
    use \Base\Database\Entity\Extension\TranslatableTrait;

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-sync'];
    }

    public function __construct(?Product $product = null, int $unitPrice = 0, ?string $currency = null)
    {
        parent::__construct($product?->getOwner(), null, $unitPrice, $currency);
        $this->setParent($product);
    }

    /**
     * @return true
     */
    public static function isVariant()
    {
        return true;
    }

    public function getPrincipal(): ?Product
    {
        return $this->getParent();
    }

    /**
     * @param Product|null $wallpaper
     * @return Variant
     */
    public function setPrincipal(?Product $wallpaper)
    {
        return $this->setParent($wallpaper);
    }

    /**
     * @param int|null $i
     * @param $inheritIfNotSet
     * @return Image|null
     */
    public function getImage(?int $i = null, $inheritIfNotSet = !Product::INHERITS_FROM): ?Image
    {
        return $this->getPrincipal() && !count($this->images ?? []) && $inheritIfNotSet ? $this->getPrincipal()->getImage($i) : parent::getImage($i);
    }

    /**
     * @param string $slug
     * @param int|null $i
     * @param $inheritIfNotSet
     * @return ImageCrop|null
     */
    public function getImageCrop(string $slug, ?int $i = null, $inheritIfNotSet = !Product::INHERITS_FROM): ?ImageCrop
    {
        return $this->getPrincipal() && !count($this->images ?? []) && $inheritIfNotSet ? $this->getPrincipal()->getImageCrop($slug, $i) : parent::getImageCrop($slug, $i);
    }

    /**
     * @param $inheritIfNotSet
     * @return Collection
     */
    public function getImages($inheritIfNotSet = !Product::INHERITS_FROM): Collection
    {
        return $this->getPrincipal() && !count($this->images ?? []) && $inheritIfNotSet ? $this->getPrincipal()->getImages() : parent::getImages();
    }

    /**
     * @param $inheritIfNotSet
     * @return array|mixed|File
     */
    public function getImageMarketplaces($inheritIfNotSet = !Product::INHERITS_FROM)
    {
        return $this->getPrincipal() && !count($this->imageMarketplaces ?? []) && $inheritIfNotSet ? $this->getPrincipal()->getImageMarketplaces() : parent::getImageMarketplaces() ?? [];
    }

    public function getTitle(?string $locale = null, int $inheritanceDepthIfNotSet = 1): ?string
    {
        return parent::getTitle($locale, $inheritanceDepthIfNotSet);
    }

    public function getHeadline(?string $locale = null, int $inheritanceDepthIfNotSet = 1): ?string
    {
        return parent::getHeadline($locale, $inheritanceDepthIfNotSet);
    }

    public function getExcerpt(?string $locale = null, int $inheritanceDepthIfNotSet = 1): ?string
    {
        return parent::getExcerpt($locale, $inheritanceDepthIfNotSet);
    }

    public function getContent(?string $locale = null, int $inheritanceDepthIfNotSet = 1): ?string
    {
        return parent::getContent($locale, $inheritanceDepthIfNotSet);
    }

    public function getKeywords(?string $locale = null, int $inheritanceDepthIfNotSet = 1): array
    {
        return parent::getKeywords($locale, $inheritanceDepthIfNotSet);
    }

    public function getStore(): ?Store
    {
        return $this->getPrincipal() ? $this->getPrincipal()->getStore() : null;
    }

    /**
     * @param Store|null $store
     * @return Product|null
     */
    public function setStore(?Store $store)
    {
        return $this->getPrincipal() ? $this->getPrincipal()->setStore($store) : null;
    }

    /**
     * @param $inheritIfNotSet
     * @return Collection
     */
    public function getTaxa($inheritIfNotSet = Product::INHERITS_FROM): Collection
    {
        return $this->getPrincipal() && !count($this->taxa) && $inheritIfNotSet ? $this->getPrincipal()->getTaxa() : parent::getTaxa();
    }

    /**
     * @param $inheritIfNotSet
     * @return Collection
     */
    public function getTags($inheritIfNotSet = Product::INHERITS_FROM): Collection
    {
        return $this->getPrincipal() && !count($this->tags) && $inheritIfNotSet ? $this->getPrincipal()->getTags() : parent::getTags();
    }

    /**
     * @param int $i
     * @param $inheritIfNotSet
     * @return mixed|null
     */
    public function getOwner(int $i = 0, $inheritIfNotSet = Product::INHERITS_FROM)
    {
        // getPrincipal(), like every other inheriting accessor on this class
        // - and with the same null guard they all have. This read
        // `$this->getWallpaper()`, a method no generic variant has (the app's
        // wallpaper variant extends Wallpaper, not this class), so asking a
        // plain variant for an inherited owner was a fatal rather than a
        // fallback. Compare getOwners() directly below, which was always right.
        return $this->getPrincipal() && !count($this->owners) && $inheritIfNotSet
            ? $this->getPrincipal()->getOwner($i)
            : (parent::getOwners()->containsKey($i) ? parent::getOwners()->get($i) : null);
    }

    /**
     * @param $inheritIfNotSet
     * @return Collection
     */
    public function getOwners($inheritIfNotSet = Product::INHERITS_FROM): Collection
    {
        return $this->getPrincipal() && !count($this->owners) && $inheritIfNotSet ? $this->getPrincipal()->getOwners() : parent::getOwners();
    }

    /**
     * @param int $i
     * @param $inheritIfNotSet
     * @return mixed|null
     */
    public function getAuthor(int $i = 0, $inheritIfNotSet = Product::INHERITS_FROM)
    {
        return $this->getOwner($i, $inheritIfNotSet);
    }

    /**
     * @param $inheritIfNotSet
     * @return Collection
     */
    public function getAuthors($inheritIfNotSet = Product::INHERITS_FROM): Collection
    {
        return $this->getOwners($inheritIfNotSet);
    }
}
