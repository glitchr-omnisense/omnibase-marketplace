<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Supply;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An order is paid: what in it is made to order goes to its suppliers.
 * After the listeners that deliver rights (priority below theirs): a
 * supplier's failure is kept on its job, never thrown.
 */
#[AsEventListener(priority: -64)]
final class SupplyListener
{
    public function __construct(private readonly Supply $supply)
    {
    }

    public function __invoke(OrderPaidEvent $event): void
    {
        $this->supply->dispatch($event->order);
    }
}
