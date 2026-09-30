<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Order\Method\ShippingMethod;
use Base\Marketplace\Repository\Sales\Attribute\ShippingMethodScopeRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractScope;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingMethodScopeRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_shippingMethod')]
class ShippingMethodScope extends AbstractScope
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
    #[ORM\ManyToOne(targetEntity: ShippingMethod::class, inversedBy: 'scopes')]
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
