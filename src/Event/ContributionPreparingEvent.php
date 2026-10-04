<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Wishlist\Contribution;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A contribution is about to be paid: the platform's fee on it is
 * marketplace.wishlist.fee_rate of the gift plus fee_fixed, unless a
 * listener says otherwise ($fee, in minor units) - a rate that depends on
 * the owner's plan, say. $metadata goes to the provider and comes back on
 * its webhooks.
 */
final class ContributionPreparingEvent extends Event
{
    /** @param array<string, scalar> $metadata */
    public function __construct(
        public readonly Contribution $contribution,
        public int $fee,
        public array $metadata = [],
        public ?string $description = null,
    ) {
    }
}
