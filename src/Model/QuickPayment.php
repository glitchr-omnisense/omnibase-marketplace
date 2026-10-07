<?php

namespace Base\Marketplace\Model;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Payment\PaymentResult;

/** What QuickOrder::buy() hands back: the order made, and where its payment stands. */
final class QuickPayment
{
    public function __construct(public readonly Order $order, public readonly PaymentResult $result)
    {
    }
}
