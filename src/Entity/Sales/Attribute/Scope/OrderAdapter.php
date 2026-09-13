<?php

namespace Base\Market\Entity\Sales\Attribute\Scope;

use Base\Market\Entity\Order;
use Base\Market\Repository\Sales\Attribute\Scope\OrderAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_order')]
class OrderAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Order::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => Order::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Order;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Order) {
            return $subject->getId() == $value->getId();
        }
        if ($subject instanceof Order\OrderItem) {
            return $this->contains($value, $subject->getOrder());
        }

        return parent::contains($value, $subject);
    }
}
