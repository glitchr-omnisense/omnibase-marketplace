<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Attribute\Barcode;
use Base\Marketplace\Entity\Product\Identifier;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * A product's SKU, which the payment gateway names on each line: the SKU among its identifiers'
 * barcodes - none for a product sold without one. Stripe's checkout called getSku() on every
 * product, and Product had no such method: every order through omnitrade broke.
 */
class ProductSkuTest extends TestCase
{
    public function testTheSkuIsTheSkuBarcodeOfAnIdentifier(): void
    {
        self::assertSame('GLK-LIC-1', $this->product([['EAN13', '4006381333931'], ['SKU', 'GLK-LIC-1']])->getSku());
        self::assertNull($this->product([['EAN13', '4006381333931']])->getSku(), 'no SKU among them');
        self::assertNull($this->product([])->getSku(), 'no identifier: a licence, an hour pack');
    }

    /** @param list<array{0: string, 1: string}> $barcodes */
    private function product(array $barcodes): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['__toString'])->getMock();
        $identifiers = new ArrayCollection();
        if ($barcodes) {
            $identifier = $this->createMock(Identifier::class);
            $identifier->method('getBarcodes')->willReturn(new ArrayCollection(array_map(function (array $b) {
                $barcode = $this->createMock(Barcode::class);
                $barcode->method('getStandard')->willReturn($b[0]);
                $barcode->method('getValue')->willReturn($b[1]);

                return $barcode;
            }, $barcodes)));
            $identifiers->add($identifier);
        }
        (fn () => $this->identifiers = $identifiers)->call($product);

        return $product;
    }
}
