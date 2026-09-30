<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Scope;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;
use Base\Entity\User;
use Base\Marketplace\Repository\Sales\Attribute\Scope\RegionAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegionAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_region')]
class RegionAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Region::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => Region::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Region;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Region) {
            return $subject->getId() == $value->getId();
        }
        if ($subject instanceof Taxon) {
            return $this->contains($value, $subject->getStore());
        }
        if ($subject instanceof Product) {
            return $this->contains($value, $subject->getStore());
        }
        if ($subject instanceof Store) {
            return $subject->getRegions()->exists(fn ($key, $element) => $element->getId() == $value->getId());
        }
        if ($subject instanceof User) {
            return $value->getSlug() == User::getCookie('country');
        }

        if ($subject instanceof Order\OrderItem) {
            return $this->contains($value, $subject->getOrder());
        }
        if ($subject instanceof Order) {
            return $this->contains($value, $subject->getRegion());
        }

        return parent::contains($value, $subject);
    }
}
