<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Rule;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Repository\Sales\Attribute\Rule\CartQuantityAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Enum\Operation;
use Base\Field\Type\NumberType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartQuantityAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_cartQuantity')]
class CartQuantityAdapter extends AbstractRuleAdapter
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
        return [];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return true;
    }

    public function compliesWith(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Order) {
            $nItems = $subject->getItems()->count();
            switch ($this->operation) {
                case Operation::LT:
                    return $nItems < $value;
                case Operation::LTE:
                    return $nItems <= $value;
                case Operation::EQ:
                    return $nItems == $value;
                case Operation::GTE:
                    return $nItems >= $value;
                case Operation::GT:
                    return $nItems > $value;

                case Operation::NEQ:
                    return $nItems != $value;
            }
        }

        return false;
    }

    #[ORM\Column(type: 'operation')]
    protected $operation;

    /**
     * @return mixed
     */
    public function getOperation()
    {
        return $this->operation;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function setOperation($operation)
    {
        $this->operation = $operation;

        return $this;
    }
}
