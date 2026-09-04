<?php

namespace Base\Market\Entity\Sales\Attribute;

use Base\Market\Entity\Order\Method\ShippingMethod;
use Base\Market\Repository\Sales\Attribute\ShippingMethodScopeRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractScope;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ShippingMethodScopeRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "scope_shippingMethod" )
 */
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

    /**
     * @ORM\ManyToOne(targetEntity=ShippingMethod::class, inversedBy="scopes")
     *
     * @ORM\JoinColumn(nullable=false)
     */
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
