<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Sales\Referral\Referral;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A referee's first order is paid: before the referral qualifies, a
 * listener may refuse it ($rejection: a reason of its own) or tell what the
 * order was paid with ($fingerprint: the card's, as the provider gives it)
 * so the same card as the referrer's is refused.
 */
final class ReferralQualifyingEvent extends Event
{
    public ?string $rejection = null;

    public function __construct(
        public readonly Referral $referral,
        public readonly Order $order,
        public ?string $fingerprint = null,
    ) {
    }
}
