<?php

namespace Base\Market\Entity\Sales\Attribute\Scope;

use Base\Market\Entity\Order;
use Base\Market\Entity\Product;
use Base\Market\Entity\Product\Taxon;
use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Store;
use Base\Market\Repository\Sales\Attribute\Scope\StoreAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StoreAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_store')]
class StoreAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Store::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => Store::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Store;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Product) {
            return $subject->getStore()->getId() == $value->getId();
        }
        if ($subject instanceof Taxon) {
            return $subject->getStore()->getId() == $value->getId();
        }
        if ($subject instanceof Region) {
            return $subject->getStores()->exists(fn ($key, $element) => $element->getId() == $value->getId());
        }
        if ($subject instanceof Store) {
            return $subject->getId() == $value->getId();
        }
        if ($subject instanceof Order\OrderItem) {
            return $this->contains($value, $subject->getProduct());
        }
        if ($subject instanceof Order) {
            return $subject->getStore()->getId() == $value->getId();
        }

        return parent::contains($value, $subject);
    }
}
