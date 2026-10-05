<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductAvailability;
use PHPUnit\Framework\TestCase;

/** A product's availability is never nothing: a form sent without it keeps the one the product had. */
final class AvailabilityTest extends TestCase
{
    public function testGivenNoneAProductKeepsItsAvailability(): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $product->setAvailability(ProductAvailability::OUT_OF_STOCK);

        $product->setAvailability(null);
        self::assertSame(ProductAvailability::OUT_OF_STOCK, $product->getAvailability());
        $product->setAvailability('');
        self::assertSame(ProductAvailability::OUT_OF_STOCK, $product->getAvailability());

        $product->setAvailability(ProductAvailability::INSTOCK);
        self::assertSame(ProductAvailability::INSTOCK, $product->getAvailability());
        self::assertTrue($product->isForSell());
    }
}
