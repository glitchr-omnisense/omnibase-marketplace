<?php

namespace Base\Market\Entity\Sales\Attribute;

use Base\Market\Entity\Order\Method\ShippingMethod;
use Base\Market\Repository\Sales\Attribute\ShippingMethodRuleRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractRule;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ShippingMethodRuleRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "rule_shippingMethod" )
 */
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

    /**
     * @ORM\ManyToOne(targetEntity=ShippingMethod::class, inversedBy="rules")
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
