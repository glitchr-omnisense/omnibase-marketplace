<?php

namespace Base\Market\Entity\Sales;

use Base\Market\Entity\Order;
use Base\Market\Entity\Sales\Attribute\DiscountAction;
use Base\Market\Entity\Sales\Attribute\DiscountRule;
use Base\Market\Entity\Sales\Attribute\DiscountScope;
use Base\Market\Repository\Sales\DiscountRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\InheritanceType('JOINED')]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Discount implements IconizeInterface
{
    use BaseTrait;

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getLabel() ?? '';
    }

    public function __construct()
    {
        $this->orders = new ArrayCollection();
        $this->rules = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->scopes = new ArrayCollection();
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-percent'];
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

    #[ORM\Column(type: 'datetime')]
    protected $validAt;

    public function getValidAt(): ?\DateTimeInterface
    {
        return $this->validAt;
    }

    public function setValidAt(?\DateTimeInterface $validAt): self
    {
        $this->validAt = $validAt;

        return $this;
    }

    #[ORM\Column(type: 'datetime')]
    protected $expiredAt;

    public function getExpiredAt(): ?\DateTimeInterface
    {
        return $this->expiredAt;
    }

    public function setExpiredAt(?\DateTimeInterface $expiredAt): self
    {
        $this->expiredAt = $expiredAt;

        return $this;
    }

    #[ORM\ManyToMany(targetEntity: Order::class, mappedBy: 'discounts')]
    protected $orders;

    public function getCountOrders(): int
    {
        return $this->orders?->count() ?? 0;
    }

    public function setCountOrders(mixed $countOrder): self
    {
        return $this;
    }

    // mapped false ?
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders[] = $order;
            $order->addDiscount($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): self
    {
        if ($this->orders->removeElement($order)) {
            $order->removeDiscount($this);
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: DiscountRule::class, mappedBy: 'discount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $rules;

    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(DiscountRule $rule): self
    {
        if (!$this->rules->contains($rule)) {
            $this->rules[] = $rule;
            $rule->setDiscount($this);
        }

        return $this;
    }

    public function removeRule(DiscountRule $rule): self
    {
        if ($this->rules->removeElement($rule)) {
            // set the owning side to null (unless already changed)
            if ($rule->getDiscount() === $this) {
                $rule->setDiscount(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: DiscountAction::class, mappedBy: 'discount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $actions;

    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function getFormattedActions(): string
    {
        $actionList = [];
        foreach ($this->getActions() as $action) {
            $actionList[] = $action->resolve();
        }

        return implode(' / ', $actionList);
    }

    public function addAction(DiscountAction $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions[] = $action;
            $action->setDiscount($this);
        }

        return $this;
    }

    public function removeAction(DiscountAction $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getDiscount() === $this) {
                $action->setDiscount(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: DiscountScope::class, mappedBy: 'discount', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $scopes;

    public function getScopes(): Collection
    {
        return $this->scopes;
    }

    public function addScope(DiscountScope $scope): self
    {
        if (!$this->scopes->contains($scope)) {
            $this->scopes[] = $scope;
            $scope->setDiscount($this);
        }

        return $this;
    }

    public function removeScope(DiscountScope $scope): self
    {
        if ($this->scopes->removeElement($scope)) {
            // set the owning side to null (unless already changed)
            if ($scope->getDiscount() === $this) {
                $scope->setDiscount(null);
            }
        }

        return $this;
    }
}
