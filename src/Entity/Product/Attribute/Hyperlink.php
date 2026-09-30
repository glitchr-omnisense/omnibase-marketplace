<?php

namespace Base\Marketplace\Entity\Product\Attribute;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Product\Attribute\HyperlinkRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HyperlinkRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'hyperlink_product')]
class Hyperlink extends \Base\Entity\Layout\Attribute\Hyperlink
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-clipboard-list'];
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'exports')]
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
