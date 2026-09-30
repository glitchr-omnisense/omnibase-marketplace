<?php

namespace Base\Marketplace\Shopify\Export;

use Base\Marketplace\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the order as it is now and pushes it.
 *
 * Exceptions are deliberately not caught: Messenger's retry strategy and its
 * failure transport are the point of going through the bus at all, and
 * swallowing here would throw both away.
 */
#[AsMessageHandler]
final class PushOrderHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderExporter $exporter,
    ) {
    }

    public function __invoke(PushOrderMessage $message): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($message->orderId);
        if (!$order) {
            // Deleted between dispatch and consumption: nothing to retry.
            return;
        }

        $this->exporter->export($order);
    }
}
