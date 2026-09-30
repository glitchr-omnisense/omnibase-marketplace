<?php

namespace Base\Marketplace\Shopify\Export;

use Base\Marketplace\Event\OrderPaidEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * An order was paid: queue it for Shopify.
 *
 * Queue, not push. Checkout::confirm() dispatches OrderPaidEvent
 * synchronously, and it is itself usually called from inside somebody's
 * webhook request - Stripe's, or Shopify's own. Calling Shopify from here
 * would put a second network round trip inside a webhook that the caller
 * times out; worse, if it threw, the payment provider would retry the
 * webhook, confirm() would return early on its idempotency guard, and the
 * push would be silently lost. Messenger turns that into a retry.
 *
 * Nothing this class does may break a sale. The dispatch is wrapped, and a
 * failure is logged and swallowed: an order that is paid is paid, whether or
 * not Shopify has heard about it yet, and the reconcile command can pick up
 * the pieces.
 *
 * If the host does not route PushOrderMessage to an async transport, Symfony
 * handles it synchronously - slower, but correct.
 */
final class OrderPaidSubscriber
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly OrderExporter $exporter,
        #[Autowire('%marketplace.shopify.export.enabled%')] private readonly bool $enabled = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: OrderPaidEvent::class)]
    public function __invoke(OrderPaidEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        $order = $event->order;
        $id = $order->getId();
        if (null === $id || !$this->exporter->shouldExport($order)) {
            return;
        }

        try {
            $this->bus->dispatch(new PushOrderMessage($id));
        } catch (\Throwable $e) {
            $this->logger?->error('Could not queue order {reference} for Shopify: {message}', [
                'reference' => $order->getReference(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
