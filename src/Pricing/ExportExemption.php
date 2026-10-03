<?php

namespace Base\Marketplace\Pricing;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Store;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Goods leaving the European Union carry no VAT: an order delivered outside
 * it (its shipping address - a quote's order has its quote's) by a seller
 * inside it is invoiced without, and the invoice says why - article 262 I of
 * the French CGI for a French seller, article 146 of the VAT directive
 * otherwise. The customs side (the export declaration, the excise document
 * for alcohol) stays the seller's, off the site.
 *
 * After the store's own regime (StoreVatRegime, 100), before a reverse
 * charge (ReverseCharge, 50): an export is exempt whoever buys.
 */
#[AsTaggedItem(priority: 75)]
final class ExportExemption implements VatExemptionInterface
{
    public const MENTION = 'Exonération de TVA - article 262 I du CGI (livraison à l\'exportation)';
    public const MENTION_EU = 'VAT exempt - export of goods, Article 146 of Council Directive 2006/112/EC';

    /** The member states, by ISO 3166-1 alpha-2 (Greece is GR here, EL only in VIES). */
    public const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'GR', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'MC'];

    public function __construct(#[Autowire('%marketplace.shipping.sender.country%')] private readonly ?string $sellerCountry = 'FR')
    {
    }

    public function exempts(Order $order): ?string
    {
        $destination = strtoupper((string) $order->getShippingAddress()?->getCountry());
        if ('' === $destination || self::inEu($destination)) {
            return null;
        }
        $seller = $this->sellerOf($order);
        if (null === $seller || !self::inEu($seller)) {
            return null;
        }

        return 'FR' === $seller ? self::MENTION : self::MENTION_EU;
    }

    /** Whether a country is inside the EU's VAT territory (Monaco is, with France). */
    public static function inEu(string $country): bool
    {
        $country = strtoupper($country);

        return \in_array('EL' === $country ? 'GR' : $country, self::EU, true);
    }

    /** The seller's country: its store's VAT number's, else the shipping sender's. */
    private function sellerOf(Order $order): ?string
    {
        $store = $order->getStore();
        if ($store instanceof Store && $store->getVatNumber()) {
            $prefix = strtoupper(substr($store->getVatNumber(), 0, 2));

            return 'EL' === $prefix ? 'GR' : $prefix;
        }

        return $this->sellerCountry ? strtoupper($this->sellerCountry) : null;
    }
}
