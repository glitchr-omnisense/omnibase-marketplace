<?php

namespace Base\Market\Entity\Order;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\ShippingMethod;
use Base\Market\Repository\Order\ShipmentRepository;
use Base\Database\Attribute\Timestamp;
use Base\Database\Attribute\Cache;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\LinkableInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[ORM\Entity(repositoryClass: ShipmentRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class Shipment implements IconizeInterface, LinkableInterface
{
    /**
     * @return string|null
     */
    public function __toString()
    {
        return $this->getNumber();
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getMethod()->getTrackingUrl();
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-fw fa-shipping-fast'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'shipments')]
    protected $order;

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: ShippingMethod::class)]
    protected $method;

    public function getMethod(): ?ShippingMethod
    {
        return $this->method;
    }

    public function setMethod(?ShippingMethod $method): self
    {
        $this->method = $method;

        return $this;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: 'create')]
    protected $createdAt;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: ['update', 'create'])]
    protected $updatedAt;

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    #[ORM\ManyToMany(targetEntity: OrderItem::class, mappedBy: 'shipments')]
    protected $items;

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items[] = $item;
            $item->addShipment($this);
        }

        return $this;
    }

    public function removeItem(OrderItem $item): self
    {
        if ($this->items->removeElement($item)) {
            $item->removeShipment($this);
        }

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255)]
    protected $number;

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): self
    {
        $this->number = $number;

        return $this;
    }
}
