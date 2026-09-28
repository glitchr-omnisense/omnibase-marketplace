<?php

namespace Base\Market\Entity\Sales\Attribute\Rule;

use Base\Market\Entity\Order;
use Base\Market\Repository\Sales\Attribute\Rule\TotalPriceAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Enum\Operation;
use Base\Field\Type\MoneyType;
use Base\Traits\BaseTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TotalPriceAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_totalPrice')]
class TotalPriceAdapter extends AbstractRuleAdapter
{
    use BaseTrait;

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-money-bill'];
    }

    public static function getType(): string
    {
        return MoneyType::class;
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
            $salePrice = $this->getTrading()->convert($subject->getGrossPrice(), $subject->getRegion()->getCurrency(), $this->getCurrency());

            switch ($this->operation) {
                case Operation::LT:
                    return $salePrice < $value;
                case Operation::LTE:
                    return $salePrice <= $value;
                case Operation::EQ:
                    return $salePrice == $value;
                case Operation::GTE:
                    return $salePrice >= $value;
                case Operation::GT:
                    return $salePrice > $value;

                case Operation::NEQ:
                    return $salePrice != $value;
            }
        }

        return false;
    }

    #[ORM\Column(type: 'text')]
    protected $currency;

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @param string $currency
     * @return $this
     */
    /**
     * @param string $currency
     * @return $this
     */
    public function setCurrency(string $currency)
    {
        $this->currency = $currency;

        return $this;
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
