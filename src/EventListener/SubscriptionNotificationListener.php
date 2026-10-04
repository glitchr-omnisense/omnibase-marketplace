<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\PaymentNotificationEvent;
use Base\Marketplace\Service\Subscriptions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A subscription's event from a provider's webhook: applied to the Subscription kept here. */
#[AsEventListener]
final class SubscriptionNotificationListener
{
    public function __construct(private readonly Subscriptions $subscriptions)
    {
    }

    public function __invoke(PaymentNotificationEvent $event): void
    {
        $remote = $event->notification->subscription ?? null;
        if (null === $remote) {
            return;
        }
        $event->setOutcome($this->subscriptions->apply($event->gateway, $remote) ? 'subscription' : 'unknown subscription');
    }
}
