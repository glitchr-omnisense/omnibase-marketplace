<?php

namespace Base\Market\Twig;

use Base\Market\Service\Cart;
use Base\Market\Service\Pricing;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the shop's pages need from any template: the number of lines waiting
 * in the visitor's carts, for the badge next to the cart link; and prices
 * as a buyer is shown them, VAT included (prices are stored before VAT).
 *
 *   {{ market_price_with_vat(product)|... }}   cents, VAT included
 *   {{ market_vat_rate(product) }}             0.055 for 5.5 %
 */
final class MarketTwigExtension extends AbstractExtension
{
    public function __construct(private readonly Cart $cart, private readonly Pricing $pricing)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('market_cart_count', [$this->cart, 'count']),
            new TwigFunction('market_price_with_vat', [$this->pricing, 'priceWithVat']),
            new TwigFunction('market_vat_rate', [$this->pricing, 'vatRateFor']),
        ];
    }
}
