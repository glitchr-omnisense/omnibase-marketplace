<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Action;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Repository\Sales\Attribute\Action\PercentageAmountAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Field\Type\NumberType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PercentageAmountAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'action_percentageAmount')]
class PercentageAmountAdapter extends AbstractActionAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Order::__iconizeStatic();
    }

    public static function getType(): string
    {
        return NumberType::class;
    }

    public function getOptions(): array
    {
        return ['suffix' => '%', 'divisor' => 0.01];
    }

    public function resolve(mixed $value): mixed
    {
        return 100 * $value . '%';
    }

    public function apply(mixed $value, mixed $subject): mixed
    {
        if ($subject instanceof Product) {
            return $subject->getUnitPrice() * $value;
        }
        if ($subject instanceof Order) {
            return $subject->getSalePrice() * $value;
        }
        if ($subject instanceof Order\OrderItem) {
            return $subject->getGrossPrice() * $value;
        }

        return parent::apply($value, $subject);
    }

    #[ORM\Column(type: 'boolean')]
    protected $appliesToItems = false;

    public function appliesToItems(): ?bool
    {
        return $this->appliesToItems;
    }

    public function setAppliesToItems(bool $appliesToItems): self
    {
        $this->appliesToItems = $appliesToItems;

        return $this;
    }
}
