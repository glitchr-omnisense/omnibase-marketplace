<?php

namespace Base\Market\Entity\Sales\Attribute\Action;

use Base\Market\Entity\Order;
use Base\Market\Entity\Product;
use Base\Market\Repository\Sales\Attribute\Action\PercentageAmountAdapterRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Field\Type\NumberType;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=PercentageAmountAdapterRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "action_percentageAmount" )
 */
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

    /**
     * @ORM\Column(type="boolean")
     */
    protected $appliesToItems;

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
