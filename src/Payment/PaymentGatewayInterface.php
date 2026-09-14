<?php

namespace Base\Market\Payment;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;

/**
 * One way of taking money for an order.
 *
 * A PaymentMethod (an entity, configured in the admin) names its gateway by
 * `gatewayFactory`; the gateway with that name() is the one that takes the
 * payment. Services implementing this interface are tagged
 * market.payment_gateway and collected by PaymentGatewayRegistry, so an
 * application adds a gateway by writing a class - a currency of its own, a
 * card processor, a bank transfer.
 *
 * pay() does as much as it can at once and says how it went:
 *
 *   PaymentResult::paid()        the money is in (an in-game currency, a
 *                                captured card): the order is paid;
 *   PaymentResult::pending()     it will be later (a transfer, a cheque):
 *                                the order waits;
 *   PaymentResult::redirect($url) the buyer must go and pay elsewhere and
 *                                come back (a hosted card page);
 *   PaymentResult::refused($why) it will not be.
 */
interface PaymentGatewayInterface
{
    /** The value a PaymentMethod's gatewayFactory holds to use this gateway. */
    public static function name(): string;

    /** Whether this gateway can take this order at all (the currency, the buyer, the amount). */
    public function supports(Order $order, PaymentMethod $method): bool;

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult;
}
