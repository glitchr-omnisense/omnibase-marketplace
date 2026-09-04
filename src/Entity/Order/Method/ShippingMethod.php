<?php

namespace Base\Market\Entity\Order\Method;

use Base\Market\Entity\Order;
use Base\Market\Entity\Sales\Attribute\ShippingMethodRule;
use Base\Market\Entity\Sales\Attribute\ShippingMethodScope;
use Base\Market\Entity\Sales\Fee;
use Base\Market\Repository\Order\Method\ShippingMethodRepository;
use Base\Annotations\Annotation\Uploader;
use Base\Database\Annotation\Cache;
use Base\Service\Model\IconizeInterface;
use Base\Traits\BaseTrait;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @ORM\Entity(repositoryClass=ShippingMethodRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 */
class ShippingMethod implements IconizeInterface
{
    use BaseTrait;

    /**
     * @return string|null
     */
    public function __toString()
    {
        return $this->getLabel();
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-truck'];
    }

    public function __construct()
    {
        $this->rules = new ArrayCollection();
        $this->scopes = new ArrayCollection();
        $this->currency = $currency ?? $this->getSettingBag()->getScalar('app.marketplace.default_currency') ?? 'USD';
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
     * @ORM\OneToMany(targetEntity=Order::class, mappedBy="shippingMethod")
     */
    protected $orders;

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders[] = $order;
            $order->setShippingMethod($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): self
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getStore() === $this) {
                $order->setShippingMethod(null);
            }
        }

        return $this;
    }

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $gatewayName;

    public function getGatewayParameters(): array
    {
        $parameters = $this->getParameterBag()->get('omnibus.' . str_replace('-', '_', $this->getSlug()));

        return is_array($parameters) ? $parameters : [];
    }

    public function getGatewayName(): ?string
    {
        return $this->gatewayName;
    }

    public function setGatewayName(string $gatewayName): self
    {
        $this->gatewayName = $gatewayName;

        return $this;
    }

    /**
     * @return mixed|null
     */
    public function gateway()
    {
        $gateway = null;
        try {
            $gateway = null; // Omnibus::create($this->gatewayName);
            if (!$gateway) {
                return null;
            }

            foreach ($this->getGatewayParameters() as $name => $parameter) {
                $gateway->setParameter($name, $parameter);
            }
        } catch (\RuntimeException $e) {
        }

        return $gateway;
    }

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $slug;

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $label;

    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * @param string|null $label
     * @return $this
     */
    /**
     * @param string|null $label
     * @return $this
     */
    public function setLabel(?string $label)
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $shippingDelay;

    public function getShippingDelay(): ?int
    {
        return $this->shippingDelay;
    }

    public function setShippingDelay(int $shippingDelay): self
    {
        $this->shippingDelay = $shippingDelay;

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $deliveryTime;

    public function getDeliveryTime(): ?int
    {
        return $this->deliveryTime;
    }

    public function setDeliveryTime(int $deliveryTime): self
    {
        $this->deliveryTime = $deliveryTime;

        return $this;
    }

    /**
     * @ORM\Column(type="text", nullable=true)
     *
     * @Uploader(storage="local.storage", max_size="1024K", mime_types={"image/*"})
     *
     * @AssertBase\File(max_size="1024K", mime_types={"image/*"}, groups={"new", "edit"})
     */
    protected $thumbnail;

    /**
     * @return array|mixed|File|null
     */
    public function getThumbnail()
    {
        return Uploader::getPublic($this, 'thumbnail');
    }

    /**
     * @return array|mixed|File|null
     */
    public function getThumbnailFile()
    {
        return Uploader::get($this, 'thumbnail');
    }

    /**
     * @param $thumbnail
     * @return $this
     */
    /**
     * @param $thumbnail
     * @return $this
     */
    public function setThumbnail($thumbnail)
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    /**
     * @ORM\Column(type="shipping_rate")
     */
    protected $typeRate;

    /**
     * @return mixed
     */
    public function getTypeRate()
    {
        return $this->typeRate;
    }

    /**
     * @param $typeRate
     * @return $this
     */
    /**
     * @param $typeRate
     * @return $this
     */
    public function setTypeRate($typeRate): self
    {
        $this->typeRate = $typeRate;

        return $this;
    }

    /**
     * @ORM\Column(type="integer")
     */
    protected $unitPrice;

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): self
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    /**
     * @ORM\Column(type="string", length=3)
     */
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

    /**
     * @ORM\ManyToOne(targetEntity=Fee::class)
     */
    protected $serviceFee;

    public function getServiceFee(): ?Fee
    {
        return $this->serviceFee;
    }

    public function setServiceFee(?Fee $serviceFee): self
    {
        $this->serviceFee = $serviceFee;

        return $this;
    }

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    protected $trackingUrl;

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function setTrackingUrl(?string $trackingUrl): self
    {
        $this->trackingUrl = $trackingUrl;

        return $this;
    }

    /**
     * @ORM\OneToMany(targetEntity=ShippingMethodScope::class, mappedBy="shippingMethod", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $scopes;

    public function getScopes(): Collection
    {
        return $this->scopes;
    }

    public function addScope(ShippingMethodScope $scope): self
    {
        if (!$this->scopes->contains($scope)) {
            $this->scopes[] = $scope;
            $scope->setShippingMethod($this);
        }

        return $this;
    }

    public function removeScope(ShippingMethodScope $scope): self
    {
        if ($this->scopes->removeElement($scope)) {
            // set the owning side to null (unless already changed)
            if ($scope->getShippingMethod() === $this) {
                $scope->setShippingMethod(null);
            }
        }

        return $this;
    }

    /**
     * @ORM\OneToMany(targetEntity=ShippingMethodRule::class, mappedBy="shippingMethod", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $rules;

    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(ShippingMethodRule $rule): self
    {
        if (!$this->rules->contains($rule)) {
            $this->rules[] = $rule;
            $rule->setShippingMethod($this);
        }

        return $this;
    }

    public function removeRule(ShippingMethodRule $rule): self
    {
        if ($this->rules->removeElement($rule)) {
            // set the owning side to null (unless already changed)
            if ($rule->getShippingMethod() === $this) {
                $rule->setShippingMethod(null);
            }
        }

        return $this;
    }
}
