<?php

namespace Base\Market\Shopify\Api;

/**
 * Shopify quotes money as a decimal string ("12.50"); this bundle stores it
 * as an integer in the currency's minor unit (1250). The conversion is not
 * "multiply by a hundred": JPY has no minor unit at all and KWD has three.
 *
 * bcmath when it is there, so that "0.07" does not arrive as 6 through a
 * float; a rounded float otherwise, which is correct for every amount a shop
 * realistically charges.
 */
final class Money
{
    /** Currencies whose minor unit is not two digits (ISO 4217). */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function exponent(?string $currency): int
    {
        return self::EXPONENTS[strtoupper((string) $currency)] ?? 2;
    }

    /** "12.50" EUR -> 1250. "1250" JPY -> 1250. */
    public static function toMinor(string|int|float|null $amount, ?string $currency): int
    {
        if (null === $amount || '' === $amount) {
            return 0;
        }

        $exponent = self::exponent($currency);
        $amount = is_string($amount) ? trim($amount) : (string) $amount;

        if (\function_exists('bcmul')) {
            // bcmul truncates, so round half-up by hand at one extra digit.
            $scaled = bcmul($amount, bcpow('10', (string) $exponent, 0), 1);
            $rounded = bcadd($scaled, str_starts_with($scaled, '-') ? '-0.5' : '0.5', 0);

            return (int) $rounded;
        }

        return (int) round(((float) $amount) * 10 ** $exponent);
    }

    /** 1250 EUR -> "12.50", the shape Shopify wants back. */
    public static function toDecimal(int $minor, ?string $currency): string
    {
        $exponent = self::exponent($currency);

        return number_format($minor / 10 ** $exponent, $exponent, '.', '');
    }
}
