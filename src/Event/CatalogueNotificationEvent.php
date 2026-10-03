<?php

namespace Base\Marketplace\Event;

use Omnitrade\Model\Notification;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A platform said a product or a stock level changed (its webhook, read by
 * the omnitrade gateway): Catalogue\CatalogueNotificationListener brings it
 * in when the catalogue is read from that gateway. The outcome is what the
 * webhook answers.
 */
final class CatalogueNotificationEvent extends Event
{
    private ?string $outcome = null;

    public function __construct(
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
