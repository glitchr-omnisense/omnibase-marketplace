<?php

namespace Base\Market\Event;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Transaction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A payment did not happen - the member turned back on the provider's page,
 * or the provider let it expire - and the order is back to being a cart.
 * Dispatched by Checkout::cancel() after that flush.
 *
 * An application that started the payment for a purpose of its own (an
 * order it never meant as a cart) can undo it here, and, when the member is
 * coming back through the return page, say where they land: setResponse().
 * The webhook ignores the response.
 */
final class PaymentCancelledEvent extends Event
{
    private ?Response $response = null;

    public function __construct(public readonly Order $order, public readonly Transaction $transaction)
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
