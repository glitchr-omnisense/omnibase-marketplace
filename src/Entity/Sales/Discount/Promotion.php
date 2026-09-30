<?php

namespace Base\Marketplace\Entity\Sales\Discount;

use Base\Marketplace\Entity\Sales\Discount;
use Base\Marketplace\Repository\Sales\Discount\PromotionRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromotionRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry('marketplace_sales_promotion')]
class Promotion extends Discount implements IconizeInterface
{
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getLabel() ?? '';
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-bullhorn'];
    }

    #[ORM\Column(type: 'integer')]
    protected $priority;

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }
}
