<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Rule;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Repository\Sales\Attribute\Rule\CartContainsAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Field\Type\NumberType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartContainsAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_cartContains')]
class CartContainsAdapter extends AbstractRuleAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-cart-arrow-down'];
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
            return $subject->getItems()->contains($value);
        }

        return false;
    }
}
