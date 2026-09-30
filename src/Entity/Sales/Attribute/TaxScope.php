<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Tax;
use Base\Marketplace\Repository\Sales\Attribute\TaxScopeRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractScope;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxScopeRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_tax')]
class TaxScope extends AbstractScope
{
    public function get(?string $locale = null): mixed
    {
        return $this->getValue();
    }

    public function set(...$args): self
    {
        return $this;
    }

    public function resolve(?string $locale = null): mixed
    {
        return $this->adapter ? $this->adapter->resolve($this->get($locale)) : null;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Tax::class, inversedBy: 'scopes')]
    protected $tax;

    public function getTax(): ?Tax
    {
        return $this->tax;
    }

    public function setTax(?Tax $tax): self
    {
        $this->tax = $tax;

        return $this;
    }
}
