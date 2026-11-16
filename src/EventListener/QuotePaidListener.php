<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Contract\UnitOfWork;
use Base\Database\UnitOfWorks;
use Base\Database\Repository\Finder\Key;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A quote's order is paid: the quote is marked paid, the order named on it. */
#[AsEventListener]
final class QuotePaidListener
{
    public function __construct(private readonly UnitOfWork $unitOfWork)
    {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $order = $event->order;
        if (!$order->isQuoted()) {
            return;
        }
        $quote = UnitOfWorks::repositories($this->unitOfWork)->of(Quote::class)->findOneBy(['order' => Key::of($order)])
            ?? UnitOfWorks::repositories($this->unitOfWork)->of(Quote::class)->findOneBy(['reference' => $order->getQuoteReference()]);
        if ($quote instanceof Quote) {
            $quote->markPaid((string) $order->getReference());
        }
    }
}
