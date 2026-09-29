<?php

namespace Base\Market\Payment\Stripe;

use Omnipay\Stripe\Message\Checkout\PurchaseRequest;

/**
 * omnipay/stripe's Checkout session request, with what it does not send:
 *
 *   customer_email    the buyer's address, filled in on Stripe's page
 *   adaptive_pricing  off by default: the session is paid in the order's
 *                     currency, not converted to the buyer's (Stripe's
 *                     Adaptive Pricing, when the account has it on)
 */
class CheckoutPurchaseRequest extends PurchaseRequest
{
    public function getCustomerEmail(): ?string
    {
        return $this->getParameter('customerEmail');
    }

    public function setCustomerEmail(?string $value): self
    {
        return $this->setParameter('customerEmail', $value);
    }

    public function getAdaptivePricing(): bool
    {
        return (bool) $this->getParameter('adaptivePricing');
    }

    public function setAdaptivePricing(bool $value): self
    {
        return $this->setParameter('adaptivePricing', $value);
    }

    /** Why the order pays no VAT (Order::getVatExemption()): shown on the payment page, carried to the receipt. */
    public function getVatMention(): ?string
    {
        return $this->getParameter('vatMention');
    }

    public function setVatMention(?string $value): self
    {
        return $this->setParameter('vatMention', $value);
    }

    public function getData(): array
    {
        $data = array_filter(parent::getData(), static fn ($value) => null !== $value);
        if ($this->getCustomerEmail()) {
            $data['customer_email'] = $this->getCustomerEmail();
        }
        // Form-encoded: Stripe reads the strings "true" / "false".
        $data['adaptive_pricing'] = ['enabled' => $this->getAdaptivePricing() ? 'true' : 'false'];
        if ($this->getVatMention()) {
            $data['custom_text'] = ['submit' => ['message' => $this->getVatMention()]];
            $data['payment_intent_data'] = ['description' => $this->getVatMention()];
        }

        return $data;
    }
}
