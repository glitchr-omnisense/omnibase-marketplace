<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Invoices;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * marketplace.invoice.auto_issue: an order's invoice issued as soon as it is
 * paid. An invoice that cannot be issued (the seller not configured, a
 * database that fails) does not undo the payment: it is said in the log, and
 * the back office issues it.
 */
final class InvoiceOnPaymentListener
{
    public function __construct(
        private readonly Invoices $invoices,
        #[Autowire('%marketplace.invoice.auto_issue%')] private readonly bool $autoIssue = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: OrderPaidEvent::class, priority: -100)]
    public function __invoke(OrderPaidEvent $event): void
    {
        if (!$this->autoIssue || null !== $this->invoices->of($event->order)) {
            return;
        }
        try {
            $this->invoices->issue($event->order);
        } catch (\Throwable $e) {
            $this->logger?->error('The invoice of the order {order} could not be issued: {error}', ['order' => $event->order->getReference(), 'error' => $e->getMessage()]);
        }
    }
}
