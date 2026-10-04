<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Entitlement;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A right has ended - a pass run out, a subscription stopped: dispatched
 * once per entitlement by `marketplace:subscriptions`. The application
 * closes what it had opened (an archive in read-only, an export offered).
 */
final class EntitlementEndedEvent extends Event
{
    public function __construct(public readonly Entitlement $entitlement)
    {
    }
}
