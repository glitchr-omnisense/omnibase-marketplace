<?php

namespace Tests\Base\Market\Shopify;

use Base\Market\Shopify\Api\Money;
use PHPUnit\Framework\TestCase;

/**
 * Shopify quotes decimal strings, this bundle stores minor units. The bug
 * this guards against is "multiply by 100", which is wrong for a fifth of the
 * world's currencies.
 */
final class MoneyTest extends TestCase
{
    /** @dataProvider amounts */
    public function testToMinor(string $amount, string $currency, int $expected): void
    {
        self::assertSame($expected, Money::toMinor($amount, $currency));
    }

    public static function amounts(): iterable
    {
        yield 'two decimals' => ['12.50', 'EUR', 1250];
        yield 'whole number' => ['12', 'EUR', 1200];
        yield 'a cent' => ['0.01', 'EUR', 1];
        yield 'float noise' => ['0.07', 'EUR', 7];
        yield 'rounds up' => ['1.005', 'EUR', 101];
        yield 'no minor unit at all' => ['1250', 'JPY', 1250];
        yield 'three decimals' => ['0.100', 'BHD', 100];
        yield 'three decimals, whole' => ['1.500', 'KWD', 1500];
        yield 'lowercase currency' => ['9.99', 'eur', 999];
        yield 'negative' => ['-4.20', 'EUR', -420];
    }

    public function testEmptyAndNullAreZero(): void
    {
        self::assertSame(0, Money::toMinor(null, 'EUR'));
        self::assertSame(0, Money::toMinor('', 'EUR'));
    }

    /** @dataProvider roundTrips */
    public function testRoundTrip(int $minor, string $currency, string $decimal): void
    {
        self::assertSame($decimal, Money::toDecimal($minor, $currency));
        self::assertSame($minor, Money::toMinor($decimal, $currency));
    }

    public static function roundTrips(): iterable
    {
        yield [1250, 'EUR', '12.50'];
        yield [1, 'EUR', '0.01'];
        yield [1250, 'JPY', '1250'];
        yield [100, 'BHD', '0.100'];
        yield [0, 'EUR', '0.00'];
    }

    public function testExponent(): void
    {
        self::assertSame(2, Money::exponent('EUR'));
        self::assertSame(0, Money::exponent('JPY'));
        self::assertSame(3, Money::exponent('TND'));
        // An in-game currency nobody has heard of gets the sane default.
        self::assertSame(2, Money::exponent('PEP'));
        self::assertSame(2, Money::exponent(null));
    }
}
