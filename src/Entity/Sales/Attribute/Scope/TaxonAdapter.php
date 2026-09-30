<?php

namespace Base\Marketplace\Entity\Sales\Attribute\Scope;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Repository\Sales\Attribute\Scope\TaxonAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxonAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'scope_productTaxon')]
class TaxonAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Taxon::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => Taxon::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Taxon;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Taxon) {
            return $subject->getId() == $value->getId();
        }
        if ($subject instanceof Product) {
            return $subject->getTaxa()->exists(fn ($key, $element) => $element->getId() == $value->getId());
        }
        if ($subject instanceof Store) {
            return $subject->getProductTaxa()->exists(fn ($key, $element) => $element->getId() == $value->getId());
        }
        if ($subject instanceof Order\OrderItem) {
            return $this->contains($value, $subject->getProduct());
        }
        if ($subject instanceof Order) {
            foreach ($subject->getItems() as $item) {
                if ($this->contains($value, $item)) {
                    return true;
                }
            }

            return false;
        }

        return parent::contains($value, $subject);
    }
}
