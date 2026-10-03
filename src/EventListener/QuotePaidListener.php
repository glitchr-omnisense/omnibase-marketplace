<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Event\OrderPaidEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A quote's order is paid: the quote is marked paid, the order named on it. */
#[AsEventListener]
final class QuotePaidListener
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $order = $event->order;
        if (!$order->isQuoted()) {
            return;
        }
        $quote = $this->entityManager->getRepository(Quote::class)->findOneBy(['order' => $order])
            ?? $this->entityManager->getRepository(Quote::class)->findOneBy(['reference' => $order->getQuoteReference()]);
        if ($quote instanceof Quote) {
            $quote->markPaid((string) $order->getReference());
        }
    }
}
