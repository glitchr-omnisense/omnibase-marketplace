<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Entitlement;
use Symfony\Contracts\EventDispatcher\Event;

/** A right ends within marketplace.plans.reminder_days: dispatched once, for the application to tell its holder. */
final class EntitlementEndingEvent extends Event
{
    public function __construct(public readonly Entitlement $entitlement)
    {
    }
}
