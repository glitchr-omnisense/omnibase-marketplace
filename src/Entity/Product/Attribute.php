<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Product\AttributeRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttributeRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'attribute_product')]
class Attribute extends \Base\Entity\Layout\Attribute implements IconizeInterface
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-clipboard-list'];
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributes')]
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
