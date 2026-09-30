<?php

namespace Base\Marketplace\Entity\Sales;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Sales\ChannelRepository;
use Base\Database\Attribute\Hierarchify;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread\Tag;
use Base\Service\Model\IconizeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
#[\Base\Database\Attribute\Hierarchify(hierarchy: ['store', 'channels'], separator: '/')]
class Channel extends Tag implements IconizeInterface
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-random'];
    }

    public function __construct()
    {
        parent::__construct();
        $this->products = new ArrayCollection();
    }

    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'channels')]
    protected $products;

    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products[] = $product;
            $product->addChannel($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): self
    {
        if ($this->products->removeElement($product)) {
            $product->removeChannel($this);
        }

        return $this;
    }
}
