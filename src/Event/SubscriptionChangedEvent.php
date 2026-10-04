<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Enum\SubscriptionStatus;
use Symfony\Contracts\EventDispatcher\Event;

/** A subscription's state moved (renewed, past due, set to stop, ended), as the provider told. */
final class SubscriptionChangedEvent extends Event
{
    public function __construct(public readonly Subscription $subscription, public readonly ?SubscriptionStatus $previous = null)
    {
    }
}
