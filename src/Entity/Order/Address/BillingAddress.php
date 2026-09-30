<?php

namespace Base\Marketplace\Entity\Order\Address;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Repository\Order\Address\BillingAddressRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\User\Address;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BillingAddressRepository::class)]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'marketplace_billing_address')]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class BillingAddress extends Address
{
    #[ORM\OneToOne(targetEntity: Order::class, mappedBy: 'billingAddress', cascade: ['persist', 'remove'])]
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
