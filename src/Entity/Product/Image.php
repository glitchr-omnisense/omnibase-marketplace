<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Product\ImageRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Image extends \Base\Entity\Layout\Image implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-image'];
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
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
}
