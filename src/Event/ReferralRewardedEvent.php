<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Sales\Referral\Reward;
use Symfony\Contracts\EventDispatcher\Event;

/** A reward was granted (the referee's welcome, the referrer's thanks): the application tells its beneficiary. */
final class ReferralRewardedEvent extends Event
{
    public function __construct(public readonly Reward $reward)
    {
    }
}
