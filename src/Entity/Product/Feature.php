<?php

namespace Base\Market\Entity\Product;

use Base\Market\Entity\Product;
use Base\Market\Repository\Product\FeatureRepository;
use Base\Database\Attribute\Uploader;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\Alias;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread\Tag;
use Base\Service\Model\IconizeInterface;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: FeatureRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Feature extends Tag implements IconizeInterface
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-clipboard-list'];
    }

    #[Alias(column: 'threads')]
    protected $products;

    public function getProducts(): Collection
    {
        return $this->getThreads();
    }

    /**
     * @param Product $product
     * @return Feature
     */
    public function addProduct(Product $product)
    {
        return $this->addThread($product);
    }

    /**
     * @param Product $product
     * @return Feature
     */
    public function removeProduct(Product $product)
    {
        return $this->removeThread($product);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    #[AssertBase\File(max_size: '5MB', groups: ['new', 'edit'])]
    #[\Base\Database\Attribute\Uploader(max_size: '5MB', mime_types: ['image/*'])]
    protected $image;

    /**
     * @return array|mixed|File|null
     */
    public function getImage()
    {
        return \Base\Database\Attribute\Uploader::getPublic($this, 'image');
    }

    /**
     * @return array|mixed|File|null
     */
    public function getImageFile()
    {
        return \Base\Database\Attribute\Uploader::get($this, 'image');
    }

    /**
     * @param $image
     * @return $this
     */
    /**
     * @param $image
     * @return $this
     */
    public function setImage($image): self
    {
        $this->image = $image;

        return $this;
    }
}
