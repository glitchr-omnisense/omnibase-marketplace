<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Invoices;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order whose payment is confirmed (OrderPaidEvent: omnisense/omnitrade's
 * webhook, a transfer received) and whose invoice already went through a
 * gateway unpaid - a bank transfer, an invoice issued before the payment:
 * the invoice is marked paid, and "Encaissée" (212) is declared to its
 * gateway (InvoiceTransmission::declare()), pending when an approved platform
 * cannot take it yet. An invoice issued after the payment is born paid, and
 * declared paid when it is submitted.
 *
 * Registered only with omnisense/omnibill. What fails is said in the log:
 * the payment is not undone.
 */
final class InvoicePaidListener
{
    public function __construct(
        private readonly Invoices $invoices,
        private readonly InvoiceTransmission $transmission,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: OrderPaidEvent::class, priority: -110)]
    public function __invoke(OrderPaidEvent $event): void
    {
        $invoice = $this->invoices->of($event->order);
        if (null === $invoice || $invoice->isPaid() || $invoice->isCancelled() || null === $this->transmission->flowOf($invoice)) {
            return;
        }
        if ($event->order->getTotalPaid() < $invoice->getTotal()) {
            return;
        }
        try {
            $paidAt = $event->order->getPaidAt();
            $this->invoices->markPaid($invoice, null !== $paidAt ? \DateTimeImmutable::createFromInterface($paidAt) : null);
        } catch (\Throwable $e) {
            $this->logger?->error('The invoice {number} could not be marked paid: {error}', ['number' => $invoice->getNumber(), 'error' => $e->getMessage()]);
        }
    }
}
