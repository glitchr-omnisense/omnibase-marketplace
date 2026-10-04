<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupStatus;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A hand-over moved (Service\Pickups::move()): received once its order is
 * paid, accepted, ready, done... The application tells the customer, the
 * kitchen, the driver. $previous is null when it was just opened.
 */
final class PickupChangedEvent extends Event
{
    public function __construct(public readonly Pickup $pickup, public readonly ?PickupStatus $previous = null)
    {
    }
}
