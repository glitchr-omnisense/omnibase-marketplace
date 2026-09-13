<?php

namespace Base\Market\Entity\Sales\Attribute;

use Base\Market\Entity\Sales\Discount;
use Base\Market\Repository\Sales\Attribute\DiscountScopeRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractScope;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscountScopeRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_discount')]
class DiscountScope extends AbstractScope
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
    #[ORM\ManyToOne(targetEntity: Discount::class, inversedBy: 'scopes')]
    protected $discount;

    public function getDiscount(): ?Discount
    {
        return $this->discount;
    }

    public function setDiscount(?Discount $discount): self
    {
        $this->discount = $discount;

        return $this;
    }
}
