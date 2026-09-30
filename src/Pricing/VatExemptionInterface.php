<?php

namespace Base\Marketplace\Pricing;

use Base\Marketplace\Entity\Order;

/**
 * Whether an order is sold without VAT - an EU business buying from another
 * member state (reverse charge), an export... Each service implementing it
 * is asked once per repricing (tagged marketplace.vat_exemption, autoconfigured);
 * the first that exempts wins.
 *
 * exempts() answers the mention an invoice must carry (e.g. "Autoliquidation
 * - art. 283-2 du CGI", with the buyer's VAT number), kept on the order
 * (Order::getVatExemption()); null when the order pays VAT.
 */
interface VatExemptionInterface
{
    public function exempts(Order $order): ?string;
}
