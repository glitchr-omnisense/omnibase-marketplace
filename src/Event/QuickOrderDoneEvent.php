<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The page of a quick order is about to be shown to the holder of its link
 * (Service\QuickOrder::doneUrl()). An application that delivers something
 * there - files to download, a ticket - sets its own response; without one,
 * the bundle shows the order (@Marketplace/client/quick_done.html.twig).
 */
final class QuickOrderDoneEvent extends Event
{
    private ?Response $response = null;

    public function __construct(public readonly Order $order)
    {
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }

    public function setResponse(?Response $response): void
    {
        $this->response = $response;
    }
}
