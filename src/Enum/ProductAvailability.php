<?php

namespace Base\Marketplace\Enum;

use Base\Database\Type\EnumType;
use Base\Service\Model\IconizeInterface;

/**
 *
 */
class ProductAvailability extends EnumType implements IconizeInterface
{
    public const PREORDER = 'AVAL_PREORDER';
    public const PRESALE = 'AVAL_PRESALE';
    public const INSTOCK = 'AVAL_INSTOCK';
    public const SOLDOUT = 'AVAL_SOLDOUT';
    public const LIMITED = 'AVAL_LIMITED';
    public const OUT_OF_STOCK = 'AVAL_OUT_OF_STOCK';
    public const BACKORDER = 'AVAL_BACKORDER';
    public const DISCONTINUED = 'AVAL_DISCONTINUED';
    public const INSTORE_ONLY = 'AVAL_INSTORE_ONLY';
    public const ONLINE_ONLY = 'AVAL_ONLINE_ONLY';

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [
            self::PREORDER => ['fa-solid fa-shopping-basket'],
            self::PRESALE => ['fa-solid fa-cart-arrow-down'],
            self::INSTOCK => ['fa-solid fa-check-circle'],
            self::SOLDOUT => ['fa-solid fa-percent'],
            self::LIMITED => ['fa-solid fa-exclamation-circle'],
            self::OUT_OF_STOCK => ['fa-solid fa-times-circle'],
            self::BACKORDER => ['fa-solid fa-shipping-fast'],
            self::DISCONTINUED => ['fa-solid fa-cut'],
            self::INSTORE_ONLY => ['fa-solid fa-store'],
            self::ONLINE_ONLY => ['fa-solid fa-laptop'],
        ];
    }
}
