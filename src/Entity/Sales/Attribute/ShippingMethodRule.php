<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Marketplace\Repository\Sales\Attribute\ShippingMethodRuleRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractRule;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingMethodRuleRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_shippingMethod')]
class ShippingMethodRule extends AbstractRule
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
    #[ORM\ManyToOne(targetEntity: ShippingMethod::class, inversedBy: 'rules')]
    protected $shippingMethod;

    public function getShippingMethod(): ?ShippingMethod
    {
        return $this->shippingMethod;
    }

    public function setShippingMethod(?ShippingMethod $shippingMethod): self
    {
        $this->shippingMethod = $shippingMethod;

        return $this;
    }
}
