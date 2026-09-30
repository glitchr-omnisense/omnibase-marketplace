<?php

namespace Base\Marketplace\Twig;

use Base\Marketplace\Service\Shipping;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `marketplace_tracking_url(method, number)`: where a parcel can be followed. */
final class ShippingTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('marketplace_tracking_url', [Shipping::class, 'trackingUrl'])];
    }
}
