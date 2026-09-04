<?php

namespace Base\Market\Entity\Sales\Attribute;

use Base\Market\Entity\Sales\Fee;
use Base\Market\Repository\Sales\Attribute\FeeActionRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractAction;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=FeeActionRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "action_fee" )
 */
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

    /**
     * @ORM\ManyToOne(targetEntity=Fee::class, inversedBy="actions")
     *
     * @ORM\JoinColumn(nullable=false)
     */
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
