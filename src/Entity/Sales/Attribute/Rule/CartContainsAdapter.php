<?php

namespace Base\Market\Entity\Sales\Attribute\Rule;

use Base\Market\Entity\Order;
use Base\Market\Repository\Sales\Attribute\Rule\CartContainsAdapterRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Field\Type\NumberType;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=CartContainsAdapterRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "rule_cartContains" )
 */
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
