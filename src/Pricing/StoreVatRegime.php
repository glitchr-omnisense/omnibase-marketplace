<?php

namespace Base\Market\Pricing;

use Base\Market\Entity\Order;
use Base\Market\Entity\Store;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The seller's side: a store whose owner does not charge VAT (Store::
 * chargesVat(), a small business under the franchise en base) sells without
 * it, whoever buys. Asked before the buyer's side (a reverse charge only
 * matters when the store charges VAT).
 */
#[AsTaggedItem(priority: 100)]
final class StoreVatRegime implements VatExemptionInterface
{
    public const MENTION = 'TVA non applicable, art. 293 B du CGI';

    public function exempts(Order $order): ?string
    {
        $store = $order->getStore();

        return $store instanceof Store && !$store->chargesVat() ? self::MENTION : null;
    }
}
