<?php

namespace Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;

/**
 * Paid outside the site - a bank transfer, a cheque, cash on collection. The
 * order waits (pending) until someone in the admin marks it paid. The
 * instructions shown to the buyer are the method's settings:
 *
 *     marketplace:
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
