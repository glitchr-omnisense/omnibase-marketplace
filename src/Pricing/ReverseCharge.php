<?php

namespace Base\Market\Pricing;

use Base\Market\Entity\Order;
use Base\Market\Entity\Store;
use Base\Market\Model\VatCustomerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The buyer's side: a business from another EU country than the store's,
 * whose VAT number VIES confirmed (Service\VatNumbers), is invoiced without
 * VAT and accounts for it itself (article 196 of Directive 2006/112/EC). A
 * business of the store's own country, a customer without a confirmed
 * number, pays VAT.
 *
 * The store's country is its VAT number's: a store without one charges no
 * reverse charge. After the store's own regime (StoreVatRegime, priority
 * 100): a store that charges no VAT at all says so first.
 */
#[AsTaggedItem(priority: 50)]
final class ReverseCharge implements VatExemptionInterface
{
    /** A French seller's mention: the directive and the CGI. */
    public const MENTION = 'Autoliquidation - article 196 de la directive 2006/112/CE, article 283-2 du CGI';
    public const MENTION_EU = 'Reverse charge - Article 196 of Council Directive 2006/112/EC';

    /** The EU's VAT prefixes, as VIES writes them: Greece is EL, Northern Ireland XI. */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI'];

    public function exempts(Order $order): ?string
    {
        $customer = $order->getCustomer();
        $store = $order->getStore();
        if (!$customer instanceof VatCustomerInterface || !$customer->hasConfirmedVatNumber() || !$store instanceof Store || !$store->getVatNumber()) {
            return null;
        }
        $seller = substr($store->getVatNumber(), 0, 2);
        $buyer = $customer->getVatCountry();
        if ($buyer === $seller || !\in_array($buyer, self::EU, true) || !\in_array($seller, self::EU, true)) {
            return null;
        }

        $french = 'FR' === $seller;
        $mention = sprintf($french ? '%s - TVA client %s' : '%s - customer VAT %s', $french ? self::MENTION : self::MENTION_EU, $customer->getVatNumber());
        // VIES's proof that the number was valid, kept with the order.
        if ($consultation = $customer->getVatConsultationNumber()) {
            $mention .= sprintf($french ? ' - consultation VIES %s' : ' - VIES consultation %s', $consultation);
        }

        return $mention;
    }
}
