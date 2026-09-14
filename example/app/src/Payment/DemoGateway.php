<?php

namespace App\Payment;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Payment\PaymentGatewayInterface;
use Base\Market\Payment\PaymentResult;

/**
 * An application's own gateway, as small as one gets: it accepts every
 * order at once. A real one would call its payment service and answer
 * paid(), pending(), redirect($url) or refused('reason.key').
 */
final class DemoGateway implements PaymentGatewayInterface
{
    public static function name(): string
    {
        return 'demo';
    }

    public function supports(Order $order, PaymentMethod $method): bool
    {
        return true;
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        $transaction->setDetails(['note' => 'Paid by the demo gateway.']);

        return PaymentResult::paid();
    }
}
