<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Enum\QuoteStatus;

/** Small predicates on a quote's life the controllers and the voter share. */
final class QuoteStatusGuard
{
    /**
     * Accepted, its order (or one-off product) made, not paid yet: going
     * back to checkout is allowed.
     */
    public static function isAwaitingPayment(AbstractQuote $quote): bool
    {
        if (QuoteStatus::ACCEPTED !== $quote->getStatus()) {
            return false;
        }
        if ($quote instanceof Quote) {
            return null !== $quote->getOrder() && !$quote->getOrder()->isPaid();
        }

        return method_exists($quote, 'getProduct') && null !== $quote->getProduct();
    }
}
