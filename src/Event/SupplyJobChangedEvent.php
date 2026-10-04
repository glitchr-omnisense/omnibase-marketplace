<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order\SupplyJob;
use Base\Marketplace\Enum\SupplyStatus;
use Symfony\Contracts\EventDispatcher\Event;

/** A supplier's job moved (accepted, in production, shipped with its tracking, failed): the application tells the buyer. */
final class SupplyJobChangedEvent extends Event
{
    public function __construct(public readonly SupplyJob $job, public readonly ?SupplyStatus $previous = null)
    {
    }
}
