<?php

namespace Base\Market\Payment\Stripe;

use Omnipay\Stripe\CheckoutGateway as OmnipayCheckoutGateway;

/** omnipay/stripe's Checkout gateway, its purchase() our request (CheckoutPurchaseRequest). */
class CheckoutGateway extends OmnipayCheckoutGateway
{
    public function purchase(array $parameters = []): CheckoutPurchaseRequest
    {
        return $this->createRequest(CheckoutPurchaseRequest::class, $parameters);
    }
}
