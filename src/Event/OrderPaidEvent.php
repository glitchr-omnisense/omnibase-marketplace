<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An order has just been paid in full. The place for an application to
 * deliver what does not ship: unlock a download, credit an account, put an
 * item in a wardrobe. Dispatched once, after the flush that marked it paid.
 */
final class OrderPaidEvent extends Event
{
    public function __construct(public readonly Order $order)
    {
    }
}
