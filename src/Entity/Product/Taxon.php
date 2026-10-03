<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Store;
use Base\Marketplace\Repository\Product\TaxonRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxonRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'marketplace_product_taxon')]
class Taxon extends \Base\Entity\Thread\Taxon
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-tags'];
    }

    public function __construct(?string $label = null, ?string $slug = null, ?Store $store = null)
    {
        parent::__construct($label, $slug);
        $this->store = $store;
    }

    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Store::class, inversedBy: 'productTaxa')]
    protected $store;

    public function getStore(): ?Store
    {
        return $this->store ?? ($this->getParent() ? $this->getParent()->getStore() : null);
    }

    public function setStore(?Store $store): self
    {
        $this->store = $store;

        return $this;
    }

    /** Everything filed under it is sold to adults only (wine, spirits): Product::isAgeRestricted(). */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected $ageRestricted = false;

    public function isAgeRestricted(): bool
    {
        if ($this->ageRestricted) {
            return true;
        }
        $parent = $this->getParent();

        return $parent instanceof self && $parent !== $this && $parent->isAgeRestricted();
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
}
