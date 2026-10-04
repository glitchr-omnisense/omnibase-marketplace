<?php

namespace Base\Marketplace\Entity\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Sales\Region;
use Base\Entity\User;
use Base\Marketplace\Repository\Order\OrderItemRepository;
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
        $this->attachments = new ArrayCollection();
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

    /**
     * Who this line is for, when it is not the order's shipping address: a
     * card sent to each guest, a gift to somebody else.
     * {name, street: [..], postcode, city, country, email?, phone?, company?}
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected $recipient = null;

    public function getRecipient(): ?array
    {
        return $this->recipient;
    }

    public function setRecipient(?array $recipient): self
    {
        $this->recipient = $recipient ?: null;

        return $this;
    }

    /**
     * How this line is made: {files: [addresses of the print files], and any
     * words of the personalisation (a text, a monogram)}. Handed to the
     * product's supplier (Service\Supply).
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected $personalisation = null;

    public function getPersonalisation(): ?array
    {
        return $this->personalisation;
    }

    public function setPersonalisation(?array $personalisation): self
    {
        $this->personalisation = $personalisation ?: null;

        return $this;
    }

    /**
     * The options chosen for this line (Product\OptionGroup), copied when it
     * was added: [{group, option, group_label, label, price}]. Their prices
     * are in the line's unit price already.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected $options = null;

    /** @return list<array{group: ?int, option: ?int, group_label: string, label: string, price: int}> */
    public function getOptions(): array
    {
        return $this->options ?? [];
    }

    /**
     * The line takes these options: kept as a copy, their surcharge added to
     * the product's unit price (before VAT, like it).
     */
    public function applyOptions(\Base\Marketplace\Model\OptionSelection $selection, ?string $locale = null): self
    {
        if ($this->getOrder()?->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }
        $this->options = $selection->toArray($locale) ?: null;
        $this->_unitPrice = (int) $this->getProduct()?->getUnitPrice() + $selection->surcharge();

        return $this;
    }

    /** What the options add to each unit, before VAT. */
    public function getOptionsSurcharge(): int
    {
        return array_sum(array_map(fn (array $o) => (int) ($o['price'] ?? 0), $this->getOptions()));
    }

    /** "Bien cuit, Œuf mollet" - empty without options. */
    public function getOptionsLabel(): string
    {
        return implode(', ', array_map(fn (array $o) => (string) ($o['label'] ?? ''), $this->getOptions()));
    }

    /** Which options, whatever their order: two lines of a product differ by it. */
    public function getOptionsKey(): string
    {
        $ids = array_map(fn (array $o) => (int) ($o['option'] ?? 0), $this->getOptions());
        sort($ids);

        return implode('-', $ids);
    }

    /** @var Collection<int, \Base\Marketplace\Entity\Attachment> the files given for this line: the buyer's artwork for a printed item */
    #[ORM\OneToMany(targetEntity: \Base\Marketplace\Entity\Attachment::class, mappedBy: 'orderItem', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    protected $attachments;

    /** @return Collection<int, \Base\Marketplace\Entity\Attachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments ??= new ArrayCollection();
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
