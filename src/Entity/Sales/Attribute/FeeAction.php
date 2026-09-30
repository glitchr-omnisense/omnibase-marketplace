<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Fee;
use Base\Marketplace\Repository\Sales\Attribute\FeeActionRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractAction;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FeeActionRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'action_fee')]
class FeeAction extends AbstractAction
{
    public function get(?string $locale = null): mixed
    {
        return abs($this->getValue());
    }

    public function set(...$args): self
    {
        return $this;
    }

    public function resolve(?string $locale = null): mixed
    {
        return $this->adapter ? $this->adapter->resolve($this->get($locale)) : null;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Fee::class, inversedBy: 'actions')]
    protected $fee;

    public function getFee(): ?Fee
    {
        return $this->fee;
    }

    public function setFee(?Fee $fee): self
    {
        $this->fee = $fee;

        return $this;
    }

    public function appliesToItems(): bool
    {
        return false;
    } // Additional fees apply to order only.
}
