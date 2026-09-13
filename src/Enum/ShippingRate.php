<?php

namespace Base\Market\Enum;

use Base\Database\Type\EnumType;
use Base\Service\Model\IconizeInterface;

/** How a shipping method prices a parcel: one flat rate, priority, or international. */
class ShippingRate extends EnumType implements IconizeInterface
{
    public const FLAT_RATE = 'RATE_FLAT';
    public const PRIORITY_RATE = 'RATE_PRIORITY';
    public const INTERNATIONAL_RATE = 'RATE_INTERNATIONAL';

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [
            self::FLAT_RATE => ['fa-solid fa-percent'],
            self::PRIORITY_RATE => ['fa-solid fa-times-circle'],
            self::INTERNATIONAL_RATE => ['fa-solid fa-globe'],
        ];
    }
}
