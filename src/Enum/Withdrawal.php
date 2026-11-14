<?php

namespace Base\Marketplace\Enum;

/**
 * How a product stands towards a consumer's right of withdrawal (French Code de la
 * consommation L221-18 and following; EU Directive 2011/83/EU art. 9 and 16), which the
 * checkout reads to know what the buyer must state before paying (Service\Consents):
 *
 *   NONE             nothing to state beyond the terms of sale: goods, or anything the host
 *                    has not marked (the default - the bundle never guesses);
 *   DIGITAL_CONTENT  digital content not on a tangible medium, supplied as soon as it is paid
 *                    (a licence key, a download): the buyer asks for immediate supply and
 *                    acknowledges losing the right of withdrawal (L221-28 13°), or there is no
 *                    sale - the checkout requires it;
 *   SERVICE          a service performed from the payment on (hours credited at once, a job
 *                    started within the 14 days): the buyer expressly asks for performance to
 *                    begin before the period ends (L221-25), and acknowledges losing the right
 *                    once it is fully performed (L221-28 1°).
 *
 * Set on a product (Product::setWithdrawal()), or by a product class of its own
 * (Product::defaultWithdrawal(): omnibase/forge's licence offers and hour packs). Which
 * products are which is the seller's call, to check with a lawyer: docs/withdrawal.md.
 */
enum Withdrawal: string
{
    case NONE = 'none';
    case DIGITAL_CONTENT = 'digital_content';
    case SERVICE = 'service';
}
