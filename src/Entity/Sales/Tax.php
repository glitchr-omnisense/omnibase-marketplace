<?php

namespace Base\Market\Entity\Sales;

use Base\Market\Entity\Sales\Attribute\FeeScope;
use Base\Market\Entity\Sales\Attribute\TaxScope;
use Base\Market\Repository\Sales\TaxRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxRepository::class)]
#[ORM\InheritanceType('JOINED')]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Tax implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-comments-dollar'];
    }

    public function __construct()
    {
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

    #[ORM\OneToMany(targetEntity: TaxScope::class, mappedBy: 'tax', cascade: ['persist', 'remove'], orphanRemoval: true)]
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

    #[ORM\Column(type: 'float')]
    protected $rate;

    public function getRate(): ?float
    {
        return $this->rate;
    }

    public function setRate(float $rate): self
    {
        $this->rate = $rate;

        return $this;
    }
}
