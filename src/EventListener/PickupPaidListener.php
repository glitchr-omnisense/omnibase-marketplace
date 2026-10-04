<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Pickups;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** An order with a hand-over is paid: the shop has received it (saved with the confirmation's own flush). */
#[AsEventListener]
final class PickupPaidListener
{
    public function __construct(private readonly Pickups $pickups)
    {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $pickup = $this->pickups->of($event->order);
        if ($pickup?->isAtCheckout()) {
            $this->pickups->move($pickup, PickupStatus::RECEIVED, false);
        }
    }
}
