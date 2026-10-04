<?php

namespace Base\Marketplace\Enum;

/** Where a subscription stands; the values are glitchr/omnitrade's (Model\Subscription). */
enum SubscriptionStatus: string
{
    case INCOMPLETE = 'incomplete';
    case TRIALING = 'trialing';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case UNPAID = 'unpaid';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';

    /** What it gives is due. */
    public function isRunning(): bool
    {
        return \in_array($this, [self::ACTIVE, self::TRIALING, self::PAST_DUE], true);
    }
}
