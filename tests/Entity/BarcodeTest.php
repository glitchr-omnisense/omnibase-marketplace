<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Marketplace\Entity\Product\Attribute\Barcode;
use Base\Marketplace\Enum\Barcode as Standard;
use PHPUnit\Framework\TestCase;

/** A barcode keeps its value prefixed with its adapter's standard, from its creation on. */
final class BarcodeTest extends TestCase
{
    private function adapter(string $standard): BarcodeAdapter
    {
        // Without its constructor: its label is a translation, which needs the kernel.
        $adapter = (new \ReflectionClass(BarcodeAdapter::class))->newInstanceWithoutConstructor();

        return $adapter->setStandard($standard);
    }

    /** @return list<string> the deprecations and notices raised by $create */
    private function raised(callable $create): array
    {
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });
        try {
            $create();
        } finally {
            restore_error_handler();
        }

        return $raised;
    }

    public function testANewBarcodeRaisesNothingAndKeepsItsValue(): void
    {
        $barcode = null;
        self::assertSame([], $this->raised(function () use (&$barcode) {
            $barcode = new Barcode($this->adapter(Standard::EAN), '3 760 123 456 789');
        }), 'the adapter is set before any value');

        self::assertSame('3760123456789', $barcode->getValue());
        self::assertSame(Standard::EAN, $barcode->getStandard());
    }

    public function testABarcodeWithoutValueGetsAnIdentifierItDoesNotShow(): void
    {
        $barcode = null;
        self::assertSame([], $this->raised(function () use (&$barcode) {
            $barcode = new Barcode($this->adapter(Standard::SKU));
        }));

        self::assertNull($barcode->getValue(), 'the generated uuid is not a value');
    }

    public function testAnotherAdapterPrefixesTheValueWithItsStandard(): void
    {
        $barcode = new Barcode($this->adapter(Standard::EAN), '3760123456789');
        $barcode->setAdapter($this->adapter(Standard::GTIN));

        self::assertSame(Standard::GTIN, $barcode->getStandard());
        self::assertSame('3760123456789', $barcode->getValue(), 'the former standard is dropped, not kept in the value');
    }
}
