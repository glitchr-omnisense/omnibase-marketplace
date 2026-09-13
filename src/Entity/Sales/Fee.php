<?php

namespace Base\Market\Entity\Sales;

use Base\Market\Entity\Sales\Attribute\FeeAction;
use Base\Market\Entity\Sales\Attribute\FeeRule;
use Base\Market\Entity\Sales\Attribute\FeeScope;
use Base\Market\Repository\Sales\FeeRepository;
use Base\Database\Attribute\Cache;
use Base\Service\Model\IconizeInterface;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeeRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class Fee implements IconizeInterface
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
        return ['fa-solid fa-comment-dollar'];
    }

    public function __construct()
    {
        $this->scopes = new ArrayCollection();
        $this->rules = new ArrayCollection();
        $this->actions = new ArrayCollection();
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
    protected $label = '';

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

    #[ORM\OneToMany(targetEntity: FeeScope::class, mappedBy: 'fee', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $scopes;

    public function getScopes(): Collection
    {
        return $this->scopes;
    }

    public function addScope(FeeScope $scope): self
    {
        if (!$this->scopes->contains($scope)) {
            $this->scopes[] = $scope;
            $scope->setFee($this);
        }

        return $this;
    }

    public function removeScope(FeeScope $scope): self
    {
        if ($this->scopes->removeElement($scope)) {
            // set the owning side to null (unless already changed)
            if ($scope->getFee() === $this) {
                $scope->setFee(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: FeeRule::class, mappedBy: 'fee', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $rules;

    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(FeeRule $rule): self
    {
        if (!$this->rules->contains($rule)) {
            $this->rules[] = $rule;
            $rule->setFee($this);
        }

        return $this;
    }

    public function removeRule(FeeRule $rule): self
    {
        if ($this->rules->removeElement($rule)) {
            // set the owning side to null (unless already changed)
            if ($rule->getFee() === $this) {
                $rule->setFee(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: FeeAction::class, mappedBy: 'fee', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $actions;

    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(FeeAction $action): self
    {
        if (!$this->actions->contains($action)) {
            $this->actions[] = $action;
            $action->setFee($this);
        }

        return $this;
    }

    public function removeAction(FeeAction $action): self
    {
        if ($this->actions->removeElement($action)) {
            // set the owning side to null (unless already changed)
            if ($action->getFee() === $this) {
                $action->setFee(null);
            }
        }

        return $this;
    }
}
