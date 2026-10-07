<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Controller\Client\QuickOrderController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A buyer back from the payment provider lands on marketplace_payment_return,
 * which sends anyone who is not the signed-in customer to the basket. A
 * quick order has nobody signed in: when the order is one this visitor made
 * (its id is in their session), the request goes to
 * QuickOrderController::Back() instead - same URL, its own ending.
 */
final class QuickOrderReturnListener
{
    // After the router (32) has named the route, before the controller is resolved.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'marketplace_payment_return' !== $request->attributes->get('_route') || !$request->hasPreviousSession()) {
            return;
        }
        $mine = array_map('intval', (array) $request->getSession()->get(QuickOrderController::SESSION, []));
        if (\in_array((int) $request->attributes->get('order'), $mine, true)) {
            $request->attributes->set('_controller', QuickOrderController::class.'::Back');
        }
    }
}
