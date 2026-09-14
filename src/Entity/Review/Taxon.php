<?php

namespace Base\Market\Entity\Review;

use Base\Market\Entity\Store;
use Base\Market\Repository\Review\TaxonRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxonRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'market_review_taxon')]
class Taxon extends \Base\Entity\Thread\Taxon
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-search'];
    }

    public function __construct(?string $label = null, ?string $slug = null, ?Store $store = null)
    {
        parent::__construct($label, $slug);
        $this->store = $store;
    }

    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Store::class, inversedBy: 'reviewTaxa')]
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
}
