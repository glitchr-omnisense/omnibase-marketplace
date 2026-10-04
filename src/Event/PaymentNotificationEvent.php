<?php

namespace Base\Marketplace\Event;

use Omnitrade\Model\Notification;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A provider's webhook that is not about an order's payment nor the
 * catalogue: a connected account updated, a subscription renewed or ended,
 * a payment the marketplace's orders do not know (a contribution to a list,
 * an application's own). Whoever it concerns says so (setOutcome), which
 * ends the round.
 */
final class PaymentNotificationEvent extends Event
{
    private ?string $outcome = null;

    public function __construct(
        /** The omnitrade gateway's name. */
        public readonly string $gateway,
        public readonly Notification $notification,
    ) {
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): void
    {
        $this->outcome = $outcome;
        $this->stopPropagation();
    }
}
