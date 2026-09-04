<?php

namespace Base\Market\Entity\Order\Address;

use Base\Market\Entity\Order;
use Base\Market\Repository\Order\Address\BillingAddressRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\User\Address;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BillingAddressRepository::class)
 *
 * @DiscriminatorEntry( value = "marketplace_billing_address")
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 */
class BillingAddress extends Address
{
    /**
     * @ORM\OneToOne(targetEntity=Order::class, mappedBy="billingAddress", cascade={"persist", "remove"})
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
            $this->order->setBillingAddress(null);
        }

        // set the owning side of the relation if necessary
        if (null !== $order && $order->getBillingAddress() !== $this) {
            $order->setBillingAddress($this);
        }

        $this->order = $order;

        return $this;
    }
}
