<?php

namespace Base\Market\Enum;

use Base\Database\Type\EnumType;
use Base\Service\Model\IconizeInterface;

/**
 *
 */
class Barcode extends EnumType implements IconizeInterface
{
    public const UPC = 'UPC';
    public const EAN = 'EAN';
    public const GTIN = 'GTIN';
    public const ISBN = 'ISBN';
    public const SKU = 'SKU';
    public const ASIN = 'ASIN';
    public const GCID = 'GCID';
    public const FNSKU = 'FNSKU';
    public const EPID = 'EPID';
    public const GLN = 'GLN';

    public const LINEAR = 'BARCODE';
    public const QRCODE = 'QRCODE';
    public const DATAMATRIX = 'DATAMATRIX';
    public const DOTCODE = 'DOTCODE';

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [
            self::LINEAR => ['fa-solid fa-barcode'],
            self::QRCODE => ['fa-solid fa-qrcode'],
            self::DATAMATRIX => ['fa-solid fa-qrcode'],
            self::DOTCODE => ['fa-solid fa-qrcode'],

            self::GTIN => ['fa-solid fa-barcode'],
            self::UPC => ['fa-solid fa-barcode'],
            self::EAN => ['fa-solid fa-barcode'],
            self::ISBN => ['fa-solid fa-barcode'],
            self::SKU => ['fa-solid fa-barcode'],
            self::ASIN => ['fa-solid fa-barcode'],
            self::GCID => ['fa-solid fa-barcode'],
            self::FNSKU => ['fa-solid fa-barcode'],
            self::EPID => ['fa-solid fa-barcode'],
            self::GLN => ['fa-solid fa-barcode'],
        ];
    }
}
