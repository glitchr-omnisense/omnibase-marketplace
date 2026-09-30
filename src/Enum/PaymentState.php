<?php

namespace Base\Marketplace\Enum;

use Base\Database\Type\EnumType;
use Base\Service\Model\IconizeInterface;

/**
 *
 */
class PaymentState extends EnumType implements IconizeInterface
{
    public const AWAITING = 'PAYMENT_AWAITING';
    public const CANCEL = 'PAYMENT_CANCEL';

    public const AUTHORIZE_PARTIAL = 'PAYMENT_AUTHORIZE_PARTIAL';
    public const AUTHORIZE = 'PAYMENT_AUTHORIZE';

    public const PAID_PARTIAL = 'PAYMENT_PAID_PARTIAL';
    public const PAID = 'PAYMENT_PAID';

    public const REFUND_PARTIAL = 'PAYMENT_REFUND_PARTIAL';
    public const REFUND = 'PAYMENT_REFUND';

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return [
            self::AWAITING => ['fa-solid fa-user-clock'],
            self::CANCEL => ['fa-solid fa-times-circle'],
            self::AUTHORIZE_PARTIAL => ['fa-solid fa-minus-circle'],
            self::AUTHORIZE => ['fa-solid fa-circle'],
            self::PAID_PARTIAL => ['fa-solid fa-exclamation-circle'],
            self::PAID => ['fa-solid fa-check-circle'],
            self::REFUND_PARTIAL => ['fa-solid fa-undo'],
            self::REFUND => ['fa-solid fa-undo'],
        ];
    }
}
