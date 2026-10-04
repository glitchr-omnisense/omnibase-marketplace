<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Referrals;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** An order is paid: a referee's first one qualifies their referral. */
#[AsEventListener]
final class ReferralListener
{
    public function __construct(private readonly Referrals $referrals)
    {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $this->referrals->onOrderPaid($event->order);
    }
}
