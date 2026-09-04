<?php

namespace Base\Market\Entity\Product\Attribute;

use Base\Market\Entity\Product;
use Base\Market\Repository\Product\Attribute\HyperlinkRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=HyperlinkRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry(value="hyperlink_product")
 */
class Hyperlink extends \Base\Entity\Layout\Attribute\Hyperlink
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-clipboard-list'];
    }

    /**
     * @ORM\ManyToOne(targetEntity=Product::class, inversedBy="exports")
     *
     * @ORM\JoinColumn(nullable=false)
     */
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
