<?php

namespace Base\Market\Entity\Sales\Attribute\Action;

use Base\Market\Entity\Order;
use Base\Market\Entity\Product;
use Base\Market\Repository\Sales\Attribute\Action\FixedAmountAdapterRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Field\Type\MoneyType;
use Base\Traits\BaseTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Intl\Currencies;

/**
 * @ORM\Entity(repositoryClass=FixedAmountAdapterRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "action_fixedAmount" )
 */
class FixedAmountAdapter extends AbstractActionAdapter
{
    use BaseTrait;

    public static function __iconizeStatic(): ?array
    {
        return Order::__iconizeStatic();
    }

    public static function getType(): string
    {
        return MoneyType::class;
    }

    public function getOptions(): array
    {
        return ['currency_list' => [$this->getCurrency()]];
    }

    public function resolve(mixed $value): mixed
    {
        return $value / 100 . Currencies::getSymbol($this->getCurrency());
    }

    public function apply(mixed $value, mixed $subject): mixed
    {
        if ($subject instanceof Product) {
            return $value;
        }
        if ($subject instanceof Order) {
            return $value;
        }
        if ($subject instanceof Order\OrderItem) {
            return $value;
        }

        return parent::apply($value, $subject);
    }

    /**
     * @ORM\Column(type="text")
     */
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
