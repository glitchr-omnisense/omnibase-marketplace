<?php

namespace Base\Marketplace\Catalogue;

use Base\Marketplace\Event\CatalogueNotificationEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A platform's product or stock webhook (through PaymentController's
 * /marketplace/{gateway}/webhook, checked by the omnitrade gateway): the
 * product brought in again, taken off sale when deleted, its stock
 * counted - when the catalogue is read from that gateway
 * (marketplace.catalogue.source).
 */
#[AsEventListener]
final class CatalogueNotificationListener
{
    public function __construct(
        private readonly PlatformSynchronizer $synchronizer,
        #[Autowire('%marketplace.catalogue.source%')] private readonly ?string $source = null,
    ) {
    }

    public function __invoke(CatalogueNotificationEvent $event): void
    {
        if (null === $this->source || $event->gateway !== $this->source) {
            $event->setOutcome('ignored');

            return;
        }
        $notification = $event->notification;

        if (null !== $notification->product) {
            $outcome = $this->synchronizer->synchronize($event->gateway, $notification->product);
        } elseif ([] !== $notification->stocks) {
            $outcome = 'stock:'.\count(array_filter($notification->stocks, fn ($stock) => $this->synchronizer->updateStock($event->gateway, $stock)));
        } elseif (null !== $notification->reference && str_contains($notification->event, 'delete')) {
            $outcome = 'discontinued:'.$this->synchronizer->discontinue($event->gateway, $notification->reference);
        } else {
            $outcome = 'ignored';
        }
        $this->synchronizer->flush();
        $event->setOutcome($outcome);
    }
}
