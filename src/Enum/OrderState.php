<?php

namespace Base\Market\Enum;

use Base\Database\Type\EnumType;
use Base\Service\Model\ColorizeInterface;
use Base\Service\Model\IconizeInterface;

/**
 *
 */
class OrderState extends EnumType implements IconizeInterface, ColorizeInterface
{
    public const CART = 'ORDER_CART';
    public const ABANDON = 'ORDER_CART_ABANDON';

    public const ARCHIVE = 'ORDER_ARCHIVE';

    public const PENDING = 'ORDER_PENDING';
    public const CONFIRM = 'ORDER_CONFIRM';
    public const CONFIRM_PARTIAL = 'ORDER_CONFIRM_PARTIAL';
    public const PROCESSING = 'ORDER_PROCESSING';
    public const DELAYED = 'ORDER_DELAYED';

    public const CANCEL = 'ORDER_CANCEL';

    public const SHIPPED = 'ORDER_SHIPPED';
    public const COMPLETE = 'ORDER_COMPLETE';
    public const REVIEWED = 'ORDER_COMPLETE_REVIEW';

    public const REFUND = 'ORDER_REFUND';
    public const RETURNING = 'ORDER_RETURNING';
    public const RETURN_PARTIAL = 'ORDER_RETURN_PARTIAL';
    public const RETURN_RECEIVED = 'ORDER_RETURN_RECEIVED';

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [
            self::CART => ['fa-solid fa-shopping-basket'],
            self::ABANDON => ['fa-solid fa-shopping-basket'],

            self::ARCHIVE => ['fa-solid fa-archive'],

            self::PENDING => ['fa-solid fa-hourglass-start'],
            self::CONFIRM => ['fa-solid fa-hourglass-start'],
            self::CONFIRM_PARTIAL => ['fa-solid fa-exclamation-circle'],

            self::PROCESSING => ['fa-solid fa-hourglass-half'],
            self::DELAYED => ['fa-solid fa-stopwatch'],
            self::CANCEL => ['fa-solid fa-times-circle'],
            self::COMPLETE => ['fa-solid fa-check-circle'],
            self::SHIPPED => ['fa-solid fa-check-circle'],

            self::REVIEWED => ['fa-solid fa-check-circle'],
            self::REFUND => ['fa-solid fa-undo'],
            self::RETURNING => ['fa-solid fa-undo'],
            self::RETURN_PARTIAL => ['fa-solid fa-undo'],
            self::RETURN_RECEIVED => ['fa-solid fa-undo'],
        ];
    }

    public function __colorize(): ?array
    {
        return null;
    }

    public static function __colorizeStatic(): ?array
    {
        return [
            self::CART => null,
            self::ABANDON => ['lightgrey'],
            self::ARCHIVE => ['lightgrey'],
            self::PENDING => ['red'],
            self::CONFIRM => ['green'],
            self::CONFIRM_PARTIAL => ['lightgreen'],
            self::PROCESSING => ['darkorange'],
            self::DELAYED => ['lightorange'],
            self::CANCEL => ['lightred'],
            self::COMPLETE => ['green'],
            self::SHIPPED => ['cyan'],
            self::REVIEWED => ['green'],
            self::REFUND => ['grey'],
            self::RETURNING => ['lightred'],
            self::RETURN_PARTIAL => ['lightred'],
            self::RETURN_RECEIVED => ['lightgrey'],
        ];
    }
}
