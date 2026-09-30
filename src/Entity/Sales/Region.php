<?php

namespace Base\Marketplace\Entity\Sales;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Repository\Sales\RegionRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\Alias;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread\Tag;
use Base\Service\Model\IconizeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegionRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Region extends Tag implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-globe-europe'];
    }

    public function __construct()
    {
        parent::__construct();
        $this->stores = new ArrayCollection();
        $this->orders = new ArrayCollection();
    }


    #[Alias(column: 'threads')]
    protected $stores;

    public function getStores(): Collection
    {
        return $this->stores->filter(fn ($p) => $p instanceof Store);
    }

    public function addStore(Store $stores): self
    {
        return $this->addThread($stores);
    }

    public function removeStore(Store $stores): self
    {
        return $this->removeThread($stores);
    }

    #[ORM\Column(type: 'string', length: 3)]
    protected $currency;

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    #[ORM\Column(type: 'boolean')]
    protected $enabled;

    public function isEnabled(): ?bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    #[ORM\Column(type: 'json')]
    protected $countries = [];

    public function getCountries(): ?array
    {
        return $this->countries;
    }

    public function setCountries(array $countries): self
    {
        $this->countries = $countries;

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'region')]
    protected $orders;

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders[] = $order;
            $order->setRegion($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): self
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getRegion() === $this) {
                $order->setRegion(null);
            }
        }

        return $this;
    }
}
