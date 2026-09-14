<?php

namespace Base\Market\Payment;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;

/**
 * Paid outside the site - a bank transfer, a cheque, cash on collection. The
 * order waits (pending) until someone in the admin marks it paid. The
 * instructions shown to the buyer are the method's settings:
 *
 *     market:
 *         gateways:
 *             virement: { instructions: 'IBAN FR76 ... - mention the reference' }
 */
class ManualGateway implements PaymentGatewayInterface
{
    public static function name(): string
    {
        return 'manual';
    }

    public function supports(Order $order, PaymentMethod $method): bool
    {
        return true;
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        $transaction->setDetails(['instructions' => $method->getGatewayParameters()['instructions'] ?? null]);

        return PaymentResult::pending();
    }
}
