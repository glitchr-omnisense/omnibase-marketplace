<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Variant;
use PHPUnit\Framework\TestCase;

/** Selling by the lot: a case of 6, a minimum, the cart's ceiling. */
final class LotsTest extends TestCase
{
    private function product(?int $pack, ?int $minimum = null): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setPackSize($pack);
        $product->setMinimumQuantity($minimum);

        return $product;
    }

    public function testByTheUnitByDefault(): void
    {
        $product = $this->product(null);
        self::assertSame(1, $product->getPackSize());
        self::assertSame(1, $product->getMinimumQuantity());
        self::assertSame(3, $product->boundQuantity(3));
        self::assertSame(1, $product->boundQuantity(0));
    }

    public function testACaseOfSixRoundsUpToTheCase(): void
    {
        $product = $this->product(6);
        self::assertSame(6, $product->getMinimumQuantity(), 'one case at least');
        self::assertSame(6, $product->boundQuantity(1));
        self::assertSame(12, $product->boundQuantity(7));
        self::assertSame(12, $product->boundQuantity(12));
    }

    public function testTheMinimumIsAWholeNumberOfCases(): void
    {
        $product = $this->product(6, 10);
        self::assertSame(12, $product->getMinimumQuantity());
        self::assertSame(12, $product->boundQuantity(6));
        self::assertSame(18, $product->boundQuantity(13));
    }

    public function testTheCeilingIsRoundedDownToTheCase(): void
    {
        $product = $this->product(6);
        self::assertSame(24, $product->boundQuantity(40, 28), 'at most 28: four cases');
        self::assertSame(0, $product->boundQuantity(6, 5), 'not even one case fits');
        self::assertSame(0, $this->product(6, 12)->boundQuantity(12, 10), 'nor the minimum');
    }

    public function testAVariantTakesItsPrincipalsLots(): void
    {
        $principal = $this->product(12, 24);
        $variant = (new \ReflectionClass(Variant::class))->newInstanceWithoutConstructor();
        $parent = new \ReflectionProperty(\Base\Entity\Thread::class, 'parent');
        $parent->setValue($variant, $principal);

        self::assertSame(12, $variant->getPackSize());
        self::assertSame(24, $variant->getMinimumQuantity());
        $variant->setPackSize(6);
        self::assertSame(6, $variant->getPackSize(), 'its own when it has one');
    }
}
