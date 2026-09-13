<?php

namespace Base\Market\Entity\Order;

use Base\Market\Entity\Order;
use Base\Market\Entity\Product;
use Base\Market\Entity\Sales\Region;
use Base\Entity\User;
use Base\Market\Repository\Order\OrderItemRepository;
use Base\Database\Attribute\Cache;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class OrderItem
{
    use BaseTrait;

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->product?->__toString() ?: $this->productFallback ?: 'N/A';
    }

    public function __construct(Product $product, int $quantity = 1)
    {
        $this->setProduct($product);

        $this->_quantity = $quantity;
        $this->_unitPrice = $product->getUnitPrice();
        $this->_currency = $product->getCurrency();

        $this->shipments = new ArrayCollection();
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

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    protected $order;

    public function getRegion(): ?Region
    {
        return $this->getOrder()?->getRegion();
    }

    public function getCustomer(): ?User
    {
        return $this->getOrder()?->getCustomer();
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'orderItems')]
    protected $product;

    #[ORM\Column(type: 'text')]
    protected $productFallback; // Safety parameter in case product association is deleted..

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        if ($this->product) {
            $this->productFallback = $this->product->__toString() . ' #' . $this->product->getReference();
        }

        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    protected $comment;

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    #[ORM\Column(type: 'integer')]
    protected $_quantity;

    public function getQuantity(): ?int
    {
        return $this->_quantity;
    }

    public function setQuantity(int $quantity): self
    {
        if ($this->getOrder()?->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $this->_quantity = abs($quantity);

        return $this;
    }

    #[ORM\Column(type: 'text')]
    protected $_currency;

    public function getCurrency(): string
    {
        return $this->_currency;
    }

    /**
     * @param string $currency
     * @return $this
     */
    /**
     * @param string $currency
     * @return $this
     */
    public function setCurrency(string $currency)
    {
        $this->_currency = $currency;

        return $this;
    }

    #[ORM\Column(type: 'integer')]
    private $_unitPrice;

    public function getGrossPrice(): int
    {
        return $this->getUnitPrice() * $this->getQuantity();
    }

    public function getSalePrice(): int
    {
        return $this->getGrossPrice() - $this->getDiscountCharge();
    }

    public function getSalePricePerUnit(): int
    {
        return $this->getSalePrice() / $this->getQuantity();
    }

    public function getVAT(): float
    {
        return $this->getVatCharge() / $this->getSalePrice();
    }

    public function getNetPrice(): int
    {
        return max($this->getSalePrice() + $this->getVatCharge(), 0);
    }

    public function getUnitPrice(): int
    {
        return $this->_unitPrice;
    }

    public function setUnitPrice(float|int $unitPrice, ?string $currency = null): self
    {
        if ($this->getOrder()?->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_unitPrice = (int)$this->getTrading()->convert(abs($unitPrice), $currency, $this->getCurrency());

        return $this;
    }

    #[ORM\Column(type: 'integer')]
    private $_discountCharge = 0;

    public function getDiscountCharge(): int
    {
        return $this->_discountCharge;
    }

    public function setDiscountCharge(float|int $discountCharge, ?string $currency = null): self
    {
        if ($this->getOrder()?->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_discountCharge = (int)$this->getTrading()->convert(abs($discountCharge), $currency, $this->getCurrency());

        return $this;
    }

    #[ORM\Column(type: 'integer')]
    private $_vatCharge = 0;

    public function getVatCharge(): int
    {
        return $this->_vatCharge;
    }

    public function setVatCharge(float|int $vatCharge, ?string $currency = null): self
    {
        if ($this->getOrder()?->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_vatCharge = (int)$this->getTrading()->convert(abs($vatCharge), $currency, $this->getCurrency());

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Shipment::class, inversedBy: 'items')]
    private $shipments;

    public function getShipments(): Collection
    {
        return $this->shipments;
    }

    public function addShipment(Shipment $shipment): self
    {
        if (!$this->shipments->contains($shipment)) {
            $this->shipments[] = $shipment;
        }

        return $this;
    }

    public function removeShipment(Shipment $shipment): self
    {
        $this->shipments->removeElement($shipment);

        return $this;
    }
}
