<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Discount;
use Base\Marketplace\Repository\Sales\Attribute\DiscountRuleRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractRule;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscountRuleRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_discount')]
class DiscountRule extends AbstractRule
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
    #[ORM\ManyToOne(targetEntity: Discount::class, inversedBy: 'rules')]
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
