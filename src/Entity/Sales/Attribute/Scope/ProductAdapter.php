<?php

namespace Base\Market\Entity\Sales\Attribute\Scope;

use Base\Market\Entity\Order;
use Base\Market\Entity\Product;
use Base\Market\Repository\Sales\Attribute\Scope\ProductAdapterRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\Type\SelectType;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=ProductAdapterRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "scope_product" )
 */
class ProductAdapter extends AbstractScopeAdapter
{
    public static function __iconizeStatic(): ?array
    {
        return Product::__iconizeStatic();
    }

    public static function getType(): string
    {
        return SelectType::class;
    }

    public function getOptions(): array
    {
        return ['class' => Product::class];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Product;
    }

    public function contains(mixed $value, mixed $subject): bool
    {
        if ($subject instanceof Product) {
            return $subject->getId() == $value->getId();
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
