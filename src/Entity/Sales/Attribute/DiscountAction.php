<?php

namespace Base\Market\Entity\Sales\Attribute;

use Base\Market\Entity\Sales\Discount;
use Base\Market\Repository\Sales\Attribute\DiscountActionRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractAction;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscountActionRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'action_discount')]
class DiscountAction extends AbstractAction
{
    public function get(?string $locale = null): mixed
    {
        return abs($this->getValue());
    }

    public function set(...$args): self
    {
        return $this;
    }

    public function resolve(?string $locale = null): mixed
    {
        return $this->adapter ? '-' . str_lstrip($this->adapter->resolve($this->get($locale)), '-') : null;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Discount::class, inversedBy: 'actions')]
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

    public function appliesToItems(): bool
    {
        return $this->getAdapter()?->appliesToItems() ?? false;
    }
}
