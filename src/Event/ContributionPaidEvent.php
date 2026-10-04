<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Wishlist\Contribution;
use Symfony\Contracts\EventDispatcher\Event;

/** A contribution has just been paid: dispatched once. The application thanks, notes the gift, tells the owner. */
final class ContributionPaidEvent extends Event
{
    public function __construct(public readonly Contribution $contribution)
    {
    }
}
