<?php

namespace Base\Market\Entity\Order\Method;

use Base\Market\Entity\Order;
use Base\Market\Entity\Sales\Attribute\PaymentMethodRule;
use Base\Market\Entity\Sales\Attribute\PaymentMethodScope;
use Base\Market\Entity\Sales\Fee;
use Base\Market\Repository\Order\Method\PaymentMethodRepository;
use Base\Database\Attribute\Uploader;
use Base\Database\Attribute\Cache;
use Base\Service\Model\IconizeInterface;
use Base\Traits\BaseTrait;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity(repositoryClass: PaymentMethodRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class PaymentMethod implements IconizeInterface
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
        return ['fa-regular fa-credit-card'];
    }

    public function __construct()
    {
        $this->rules = new ArrayCollection();
        $this->scopes = new ArrayCollection();
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

    #[ORM\Column(type: 'string', length: 255)]
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

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
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

    #[ORM\Column(type: 'text', nullable: true)]
    #[\Base\Database\Attribute\Uploader(max_size: '1024K', mime_types: ['image/*'])]
    #[AssertBase\File(max_size: '1024K', mime_types: ['image/*'], groups: ['new', 'edit'])]
    protected $thumbnail;

    /**
     * @return array|mixed|File|null
     */
    public function getThumbnail()
    {
        return \Base\Database\Attribute\Uploader::getPublic($this, 'thumbnail');
    }

    /**
     * @return array|mixed|File|null
     */
    public function getThumbnailFile()
    {
        return \Base\Database\Attribute\Uploader::get($this, 'thumbnail');
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

    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'paymentMethod')]
    protected $orders;

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders[] = $order;
            $order->setPaymentMethod($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): self
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getPaymentMethod() === $this) {
                $order->setPaymentMethod(null);
            }
        }

        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Fee::class)]
    protected $refundFee;

    public function getRefundFee(): ?Fee
    {
        return $this->refundFee;
    }

    public function setRefundFee(?Fee $refundFee): self
    {
        $this->refundFee = $refundFee;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255)]
    protected $gatewayFactory;

    public function getGatewayFactory(): ?string
    {
        return $this->gatewayFactory;
    }

    public function setGatewayFactory(string $gatewayFactory): self
    {
        $this->gatewayFactory = $gatewayFactory;

        return $this;
    }

    public function getGatewayConfig(): array
    {
        return array_merge($this->getGatewayParameters(), ['factory' => $this->getGatewayFactory()]);
    }

    /** The gateway's own settings, from the market.gateways.<slug> parameter. */
    public function getGatewayParameters(): array
    {
        $parameters = $this->getParameterBag('market.gateways.' . str_replace('-', '_', (string) $this->getSlug()));

        return is_array($parameters) ? $parameters : [];
    }


    #[ORM\OneToMany(targetEntity: PaymentMethodScope::class, mappedBy: 'paymentMethod', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $scopes;

    public function getScopes(): Collection
    {
        return $this->scopes;
    }

    public function addScope(PaymentMethodScope $scope): self
    {
        if (!$this->scopes->contains($scope)) {
            $this->scopes[] = $scope;
            $scope->setPaymentMethod($this);
        }

        return $this;
    }

    public function removeScope(PaymentMethodScope $scope): self
    {
        if ($this->scopes->removeElement($scope)) {
            // set the owning side to null (unless already changed)
            if ($scope->getPaymentMethod() === $this) {
                $scope->setPaymentMethod(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: PaymentMethodRule::class, mappedBy: 'paymentMethod', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $rules;

    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(PaymentMethodRule $rule): self
    {
        if (!$this->rules->contains($rule)) {
            $this->rules[] = $rule;
            $rule->setPaymentMethod($this);
        }

        return $this;
    }

    public function removeRule(PaymentMethodRule $rule): self
    {
        if ($this->rules->removeElement($rule)) {
            // set the owning side to null (unless already changed)
            if ($rule->getPaymentMethod() === $this) {
                $rule->setPaymentMethod(null);
            }
        }

        return $this;
    }
}
