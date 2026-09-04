<?php

namespace Base\Market\Entity\Order\Address;

use Base\Market\Entity\Order;
use Base\Market\Repository\Order\Address\ShippingAddressRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\User\Address;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ShippingAddressRepository::class)
 *
 * @DiscriminatorEntry( value = "marketplace_shipping_address")
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 */
class ShippingAddress extends Address
{
    /**
     * @ORM\OneToOne(targetEntity=Order::class, mappedBy="shippingAddress", cascade={"persist", "remove"})
     */
    protected $order;

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        // unset the owning side of the relation if necessary
        if (null === $order && null !== $this->order) {
            $this->order->setShippingAddress(null);
        }

        // set the owning side of the relation if necessary
        if (null !== $order && $order->getShippingAddress() !== $this) {
            $order->setShippingAddress($this);
        }

        $this->order = $order;

        return $this;
    }
}
