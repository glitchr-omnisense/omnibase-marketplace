<?php

namespace Base\Market\Entity;

use Base\Market\Annotation\OrderReference;
use Base\Market\Entity\Order\Address\BillingAddress;
use Base\Market\Entity\Order\Address\ShippingAddress;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Method\ShippingMethod;
use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Order\Shipment;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Entity\Sales\Discount;
use Base\Market\Entity\Sales\Fee;
use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Sales\Tax;
use Base\Entity\User;
use Base\Market\Model\MerchantInterface;
use Base\Market\Enum\OrderState;
use Base\Market\Model\ShippingUnitInterface;
use Base\Market\Repository\OrderRepository;
use Base\Annotations\Annotation\Timestamp;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\LinkableInterface;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @ORM\Entity(repositoryClass=OrderRepository::class)
 *
 * @ORM\InheritanceType( "JOINED" )
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @ORM\DiscriminatorColumn( name = "type", type = "string" )
 *
 * @DiscriminatorEntry(value="default")
 */
class Order implements IconizeInterface, LinkableInterface
{
    use BaseTrait;

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        $routeName = 'app_order';
        $routeParameters = array_merge($routeParameters, [
            'hash' => $this->getObfuscator()->encode([$this->getReference()]),
        ]);

        return $this->getRouter()->generate($routeName, $routeParameters, $referenceType);
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-shopping-basket', 'fa-solid fa-handshake'];
    }

    public function __toString(): string
    {
        return $this->getTranslator()->transEntity($this) . ' #' . $this->getReference() ?? '111-XXXX-YYYYYYYYYYYY';
    }

    public function __construct(?Store $store = null)
    {
        $this->shipments = new ArrayCollection();
        $this->items = new ArrayCollection();

        $this->state = OrderState::CART;
        $this->discounts = new ArrayCollection();
        $this->additionalFees = new ArrayCollection();
        $this->additionalTaxes = new ArrayCollection();

        $this->store = $store;
        $this->_currency = $store?->getCurrency() ?? $this->getSettingBag()->getScalar('app.marketplace.default_currency');
        $this->managers = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->transactions = new ArrayCollection();
    }

    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue
     *
     * @ORM\Column(type="integer")
     */
    protected $id;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @ORM\ManyToMany(targetEntity=MerchantInterface::class, inversedBy="ordersInCharge")
     */
    protected $managers;

    public function getManager(): ?MerchantInterface
    {
        return $this->getManagers()->first() ? $this->getManagers()->first() : null;
    }

    public function getManagers(): Collection
    {
        return $this->managers;
    }

    public function addManager(MerchantInterface $manager): self
    {
        if (!$this->managers->contains($manager)) {
            $this->managers[] = $manager;
        }

        return $this;
    }

    public function removeManager(MerchantInterface $manager): self
    {
        $this->managers->removeElement($manager);

        return $this;
    }

    /**
     * @ORM\Column(type="string", unique=true)
     *
     * @OrderReference(format="CCC-XXXX-YYY")
     */
    protected $reference;

    /**
     * @return mixed
     */
    public function getReference()
    {
        return $this->reference;
    }

    /**
     * @ORM\ManyToOne(targetEntity=Store::class, inversedBy="orders")
     */
    protected $store;

    public function getStore(): ?Store
    {
        return $this->store;
    }

    public function setStore(?Store $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * @ORM\ManyToOne(targetEntity=User::class, inversedBy="orders")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    protected $customer;

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(?User $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * @ORM\Column(type="order_state")
     */
    protected $state;

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state ?? OrderState::CART;

        return $this;
    }

    public function markAsConfirmed(): self
    {
        $this->state = OrderState::CONFIRM;

        return $this;
    }

    public function markAsBeingProcessed(): self
    {
        $this->state = OrderState::PROCESSING;

        return $this;
    }

    public function markAsPending(): self
    {
        $this->state = OrderState::PENDING;

        return $this;
    }

    public function markAsShipped(): self
    {
        $this->state = OrderState::SHIPPED;

        return $this;
    }

    public function markAsCompleted(): self
    {
        $this->state = OrderState::COMPLETE;

        return $this;
    }

    public function markAsCancelled(): self
    {
        $this->state = OrderState::CANCEL;

        return $this;
    }

    public function markAsRefunded(): self
    {
        $this->state = OrderState::REFUND;

        return $this;
    }

    public function markAsCart(): self
    {
        $this->state = OrderState::CART;

        return $this;
    }

    public function markAsAbandoned(): self
    {
        $this->state = OrderState::ABANDON;

        return $this;
    }

    /**
     * @ORM\OneToMany(targetEntity=Shipment::class, mappedBy="order", orphanRemoval=true, cascade={"persist", "remove"})
     */
    protected $shipments;

    public function getShipments(): Collection
    {
        return $this->shipments;
    }

    public function addShipment(Shipment $shipment): self
    {
        if (!$this->shipments->contains($shipment)) {
            $this->shipments[] = $shipment;
            $shipment->setOrder($this);
        }

        return $this;
    }

    public function removeShipment(Shipment $shipment): self
    {
        if ($this->shipments->removeElement($shipment)) {
            // set the owning side to null (unless already changed)
            if ($shipment->getOrder() === $this) {
                $shipment->setOrder(null);
            }
        }

        return $this;
    }

    /**
     * @ORM\OneToMany(targetEntity=OrderItem::class, mappedBy="order", orphanRemoval=true, cascade={"persist", "remove"})
     */
    protected $items;

    public function isEmpty(): bool
    {
        return $this->items->count() < 1;
    }

    /**
     * Every distinct product in this order.
     *
     * Generic again, as the commented-out original beside it always was: the
     * filter that had been added here kept only Wallpapers, which made the
     * name a lie everywhere except one shop and was the reason Order could
     * not move into a bundle. Callers that really do want one kind say so,
     * with getUniqueProductsOf() below.
     */
    public function getUniqueProducts(): array
    {
        return array_unique_object($this->getItems()->map(fn ($o) => $o->getProduct())->toArray());
    }

    /**
     * @param class-string $class
     */
    public function getUniqueProductsOf(string $class): array
    {
        return array_values(array_filter($this->getUniqueProducts(), fn ($p) => $p instanceof $class));
    }

    public function getItem(int $i): ?OrderItem
    {
        return $this->items[$i] ?? null;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): self
    {
        if (OrderState::CART != $this->getState()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        if (!$this->items->contains($item)) {
            $this->items[] = $item;
            $item->setOrder($this);
        }

        return $this;
    }

    public function removeItem(OrderItem $item): self
    {
        if (OrderState::CART != $this->getState()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getOrder() === $this) {
                $item->setOrder(null);
            }
        }

        return $this;
    }

    /**
     * @ORM\Column(type="datetime")
     *
     * @Timestamp(on="create")
     */
    protected $createdAt;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @ORM\Column(type="datetime")
     *
     * @Timestamp(on={"create", "update"})
     */
    protected $updatedAt;

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected $paidAt;

    public function getPaidAt(): ?\DateTimeInterface
    {
        return $this->paidAt;
    }

    /**
     * @ORM\ManyToOne(targetEntity=Region::class, inversedBy="orders")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    protected $region;

    public function getRegion(): ?Region
    {
        return $this->region;
    }

    public function setRegion(?Region $region): self
    {
        $this->region = $region;

        return $this;
    }

    /**
     * @ORM\ManyToOne(targetEntity=PaymentMethod::class, inversedBy="orders")
     */
    protected $paymentMethod;

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethod $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    /**
     * @ORM\ManyToOne(targetEntity=ShippingMethod::class, inversedBy="orders")
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

    public function getShippingUnits(): int
    {
        $shippingUnits = 0;
        foreach ($this->getItems() as $item) {
            if ($item->getProduct() instanceof ShippingUnitInterface) {
                $shippingUnits += $item->getQuantity() * $item->getProduct()->getShippingUnits();
            }
        }

        return ceil($shippingUnits);
    }

    protected $notify;

    public function notify(): ?bool
    {
        return $this->notify;
    }

    public function setNotify(bool $notify): self
    {
        $this->notify = $notify;

        return $this;
    }

    /**
     * @ORM\ManyToMany(targetEntity=Discount::class, inversedBy="orders")
     */
    protected $discounts;

    public function getDiscounts(): Collection
    {
        return $this->discounts;
    }

    public function getCoupons(): array
    {
        return $this->discounts->filter(fn($d) => $d instanceof Discount\Coupon)->getValues();
    }

    public function getPromotions(): array
    {
        return $this->discounts->filter(fn($d) => $d instanceof Discount\Promotion)->getValues();
    }

    public function addDiscount(Discount $discount): self
    {
        if (!$this->discounts->contains($discount)) {
            $this->discounts[] = $discount;
        }

        return $this;
    }

    public function removeDiscount(Discount $discount): self
    {
        $this->discounts->removeElement($discount);

        return $this;
    }

    /**
     * @ORM\ManyToMany(targetEntity=Fee::class)
     */
    protected $additionalFees;

    public function getAdditionalFees(): Collection
    {
        return $this->additionalFees;
    }

    public function addAdditionalFee(Fee $additionalFee): self
    {
        if (!$this->additionalFees->contains($additionalFee)) {
            $this->additionalFees[] = $additionalFee;
        }

        return $this;
    }

    public function removeAdditionalFee(Fee $additionalFee): self
    {
        $this->additionalFees->removeElement($additionalFee);

        return $this;
    }

    /**
     * @ORM\ManyToMany(targetEntity=Tax::class)
     */
    protected $additionalTaxes;

    public function getAdditionalTaxes(): Collection
    {
        return $this->additionalTaxes;
    }

    public function addAdditionalTax(Tax $additionalTax): self
    {
        if (!$this->additionalTaxes->contains($additionalTax)) {
            $this->additionalTaxes[] = $additionalTax;
        }

        return $this;
    }

    public function removeAdditionalTax(Tax $additionalTax): self
    {
        $this->additionalTaxes->removeElement($additionalTax);

        return $this;
    }

    /**
     * @return bool
     */
    public function isPaid()
    {
        return !str_starts_with($this->getState(), OrderState::CART);
    }

    /**
     * @return bool
     */
    public function isCompleted()
    {
        return str_starts_with($this->getState(), OrderState::COMPLETE);
    }

    /**
     * @return bool
     */
    public function isReviewed()
    {
        return str_starts_with($this->getState(), OrderState::REVIEWED);
    }

    /**
     * @return bool
     */
    public function isAbandoned()
    {
        return OrderState::ABANDON == $this->getState();
    }

    /**
     * @return bool
     */
    public function isConfirmed()
    {
        return OrderState::CONFIRM == $this->getState();
    }

    /**
     * @return bool
     */
    public function isBeingProcessed()
    {
        return OrderState::PROCESSING == $this->getState();
    }

    /**
     * @return bool
     */
    public function isCancelled()
    {
        return OrderState::CANCEL == $this->getState();
    }

    /**
     * @return bool
     */
    public function isRefunded()
    {
        return OrderState::REFUND == $this->getState();
    }

    /**
     * @return bool
     */
    public function isBeingShipped()
    {
        return OrderState::SHIPPED == $this->getState();
    }

    /**
     * @return bool
     */
    public function isPending()
    {
        return OrderState::PENDING == $this->getState();
    }

    /**
     * @return bool
     */
    public function isDelayed()
    {
        return OrderState::DELAYED == $this->getState();
    }

    /**
     * @return bool
     */
    public function isBeingReturned()
    {
        return OrderState::RETURNING == $this->getState();
    }

    /**
     * @return bool
     */
    public function isReturned()
    {
        return OrderState::RETURN_RECEIVED == $this->getState();
    }

    /**
     * @return \DateTime
     */
    public function getArrivingTime()
    {
        $this->paidAt ??= new \DateTime('now');

        $delay = $this->getShippingMethod()->getShippingDelay() + $this->getShippingMethod()->getDeliveryTime();
        $arrivingTime = clone $this->paidAt;
        $arrivingTime->modify('+' . $delay . ' days');

        return $arrivingTime;
    }

    /**
     * @ORM\Column(type="text")
     */
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

    public function getGrossPrice(): int
    {
        $grossPrice = 0;
        foreach ($this->getItems() as $item) {
            $grossPrice += (int)$this->getTradingMarket()->convert($item->getGrossPrice(), $item->getCurrency(), $this->getCurrency());
        }

        return $grossPrice;
    }

    public function getSalePrice(): int
    {
        $salePrice = 0;
        foreach ($this->getItems() as $item) {
            $salePrice += (int)$this->getTradingMarket()->convert($item->getSalePrice(), $item->getCurrency(), $this->getCurrency());
        }

        return $salePrice;
    }

    public function getVatCharge(): int
    {
        $vatCharge = 0;
        foreach ($this->getItems() as $item) {
            $vatCharge += (int)$this->getTradingMarket()->convert($item->getVatCharge(), $item->getCurrency(), $this->getCurrency());
        }

        return $vatCharge;
    }

    public function getNetPrice(): int
    {
        return max(
            $this->getSalePrice()
            + $this->getVatCharge()
            + $this->getAdditionalTaxCharge()
            + $this->getServiceCharge()
            + $this->getShippingCharge()
            - $this->getDiscountCharge(),
            0
        );
    }

    public function getTotalPaid(): int
    {
        $totalPaid = 0;
        foreach ($this->getTransactions() as $item) {
            $totalPaid += (int)$this->getTradingMarket()->convert($item->getTotalAmount(), $item->getCurrencyCode(), $this->getCurrency());
        }

        return $totalPaid;
    }

    public function getBalanceDue(): int
    {
        return max($this->getNetPrice() - $this->getTotalPaid(), 0);
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $_additionalTaxCharge = 0;

    public function getAdditionalTaxCharge(): int
    {
        return $this->_additionalTaxCharge;
    }

    public function setAdditionalTaxCharge(float|int $additionalTaxCharge, ?string $currency = null): self
    {
        if ($this->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_additionalTaxCharge = (int)$this->getTradingMarket()->convert(abs($additionalTaxCharge), $currency, $this->getCurrency());

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $_shippingCharge = 0;

    public function getShippingCharge(): int
    {
        return $this->_shippingCharge;
    }

    public function setShippingCharge(float|int $shippingCharge, ?string $currency = null): self
    {
        if ($this->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_shippingCharge = (int)$this->getTradingMarket()->convert(abs($shippingCharge), $currency, $this->getCurrency());

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $_serviceCharge = 0;

    public function getServiceCharge(): int
    {
        return $this->_serviceCharge;
    }

    public function setServiceCharge(float|int $serviceCharge, ?string $currency = null): self
    {
        if ($this->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_serviceCharge = (int)$this->getTradingMarket()->convert(abs($serviceCharge), $currency, $this->getCurrency());

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $_discountCharge = 0;

    public function getDiscountCharge(): int
    {
        return $this->_discountCharge;
    }

    public function setDiscountCharge(float|int $discountCharge, ?string $currency = null): self
    {
        if ($this->isPaid()) {
            throw new \LogicException('Command paid. Order item cannot be updated anymore');
        }

        $currency ??= $this->getCurrency();
        $this->_discountCharge = (int)$this->getTradingMarket()->convert(abs($discountCharge), $currency, $this->getCurrency());

        return $this;
    }

    /**
     * @ORM\OneToOne(targetEntity=BillingAddress::class, inversedBy="order", cascade={"persist", "remove"})
     */
    protected $billingAddress;

    public function getBillingAddress(): ?BillingAddress
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(?BillingAddress $billingAddress): self
    {
        $this->billingAddress = $billingAddress;

        return $this;
    }

    /**
     * @ORM\OneToOne(targetEntity=ShippingAddress::class, inversedBy="order", cascade={"persist", "remove"})
     */
    protected $shippingAddress;

    public function getShippingAddress(): ?ShippingAddress
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress(?ShippingAddress $shippingAddress): self
    {
        $this->shippingAddress = $shippingAddress;

        return $this;
    }

    /**
     * @ORM\OneToMany(targetEntity=Review::class, mappedBy="order")
     */
    protected $reviews;

    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(Review $review): self
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews[] = $review;
            $review->setOrder($this);
        }

        return $this;
    }

    public function removeReview(Review $review): self
    {
        if ($this->reviews->removeElement($review)) {
            // set the owning side to null (unless already changed)
            if ($review->getOrder() === $this) {
                $review->setOrder(null);
            }
        }

        return $this;
    }

    public function isAbandonable(): bool
    {
        if (!$this->updatedAt) {
            return false;
        }

        return OrderState::CART == $this->state && $this->getAbandonTime() < 0;
    }

    public static function getAbandonDelay(): int
    {
        return 15 * 24 * 3600;
    }

    public function getAbandonTime(): ?int
    {
        return $this->updatedAt ? $this->updatedAt->getTimestamp() + self::getAbandonDelay() - time() : null;
    }

    public function getAbandonTimeStr(): ?string
    {
        $abandonTime = $this->getAbandonTime();

        return $abandonTime ? $this->getTranslator()->transTime($abandonTime) : null;
    }

    public function isDeletable(): bool
    {
        if (!$this->updatedAt) {
            return false;
        }

        return OrderState::ABANDON == $this->state && $this->getDeleteTime() < 0;
    }

    public static function getDeleteDelay(): int
    {
        return 15 * 24 * 3600;
    }

    public function getDeleteTime(): ?int
    {
        return $this->updatedAt ? $this->updatedAt->getTimestamp() + self::getDeleteDelay() - time() : null;
    }

    public function getDeleteTimeStr(): ?string
    {
        $deleteTime = $this->getDeleteTime();

        return $deleteTime ? $this->getTranslator()->transTime($deleteTime) : null;
    }

    /**
     * @ORM\OneToMany(targetEntity=Transaction::class, mappedBy="order", orphanRemoval=true)
     */
    protected $transactions;

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions[] = $transaction;
            $transaction->setOrder($this);

            $this->paidAt = new \DateTime('now');
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getOrder() === $this) {
                $transaction->setOrder(null);
            }

            $this->markAsCart();
        }

        return $this;
    }
}
