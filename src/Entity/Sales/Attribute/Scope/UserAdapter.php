<?php

namespace Base\Market\Entity\Sales\Attribute\Scope;

use Base\Market\Entity\Order;
use Base\Entity\User;
use Base\Market\Repository\Sales\Attribute\Scope\UserAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_customer')]
class UserAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return User::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => User::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof User;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof User) {
            return $subject->getId() == $value->getId();
        }
        if ($subject instanceof Order) {
            return $this->contains($value, $subject->getCustomer());
        }
        if ($subject instanceof Order\OrderItem) {
            return $this->contains($value, $subject->getCustomer());
        }

        return parent::contains($value, $subject);
    }
}
