<?php

namespace Base\Market\Twig;

use Base\Market\Service\Shipping;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `market_tracking_url(method, number)`: where a parcel can be followed. */
final class ShippingTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('market_tracking_url', [Shipping::class, 'trackingUrl'])];
    }
}
