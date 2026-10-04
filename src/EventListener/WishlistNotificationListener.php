<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\PaymentNotificationEvent;
use Base\Marketplace\Wishlist\Contributions;
use Base\Marketplace\Wishlist\PayoutAccounts;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The lists' share of a provider's webhooks: a payout account updated
 * (its holder finished the onboarding, the provider asks for more), a
 * contribution's payment completed or expired.
 */
#[AsEventListener]
final class WishlistNotificationListener
{
    public function __construct(private readonly Contributions $contributions, private readonly PayoutAccounts $accounts)
    {
    }

    public function __invoke(PaymentNotificationEvent $event): void
    {
        $notification = $event->notification;
        if (null !== ($notification->account ?? null)) {
            if ($this->accounts->applyRemote($event->gateway, $notification->account)) {
                $event->setOutcome('payout account');
            }

            return;
        }
        if (null === $notification->reference) {
            return;
        }
        $contribution = $this->contributions->findByProviderReference($event->gateway, $notification->reference);
        if ($contribution) {
            $this->contributions->apply($contribution, $notification->status);
            $event->setOutcome('contribution');
        }
    }
}
