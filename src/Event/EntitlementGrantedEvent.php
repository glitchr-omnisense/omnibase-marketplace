<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Entitlement;
use Base\Marketplace\Entity\Order;
use Symfony\Contracts\EventDispatcher\Event;

/** A right has just been granted - a plan paid, a gift made: the application opens what it opens. */
final class EntitlementGrantedEvent extends Event
{
    public function __construct(public readonly Entitlement $entitlement, public readonly ?Order $order = null)
    {
    }
}
