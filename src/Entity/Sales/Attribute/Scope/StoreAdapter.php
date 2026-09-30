<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Scope;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Repository\Sales\Attribute\Scope\StoreAdapterRepository;
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
        return $value instanceof Store || is_numeric($value) || is_string($value) || is_array($value);
    }

    /**
     * The value is stored as JSON: a store id, a slug, or {id: ...} - or a
     * Store when set in memory. Subjects are compared through their store.
     */
    public function contains(mixed $value, mixed $subject): bool
    {
        $store = match (true) {
            $subject instanceof Store => $subject,
            $subject instanceof Product, $subject instanceof Taxon => $subject->getStore(),
            $subject instanceof Order\OrderItem => $subject->getProduct()?->getStore(),
            $subject instanceof Order => $subject->getStore(),
            default => null,
        };
        if ($subject instanceof Region) {
            return $subject->getStores()->exists(fn ($key, $element) => $this->matches($value, $element));
        }

        return $store instanceof Store && $this->matches($value, $store);
    }

    private function matches(mixed $value, Store $store): bool
    {
        if ($value instanceof Store) {
            return $value->getId() === $store->getId();
        }
        if (is_array($value)) {
            $value = $value['id'] ?? $value['slug'] ?? null;
        }
        if (is_numeric($value)) {
            return (int) $value === $store->getId();
        }

        return is_string($value) && $value === $store->getSlug();
    }
}
