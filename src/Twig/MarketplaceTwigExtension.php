<?php

namespace Base\Marketplace\Twig;

use Base\Marketplace\Service\Cart;
use Base\Marketplace\Service\Pricing;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the shop's pages need from any template: the number of lines waiting
 * in the visitor's carts, for the badge next to the cart link; and prices
 * as a buyer is shown them, VAT included (prices are stored before VAT).
 *
 *   {{ marketplace_price_with_vat(product)|... }}   cents, VAT included
 *   {{ marketplace_vat_rate(product) }}             0.055 for 5.5 %
 *   {{ marketplace_vat_rates(order) }}              an order's rates, each once
 */
final class MarketplaceTwigExtension extends AbstractExtension
{
    public function __construct(private readonly Cart $cart, private readonly Pricing $pricing)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('marketplace_cart_count', [$this->cart, 'count']),
            new TwigFunction('marketplace_price_with_vat', [$this->pricing, 'priceWithVat']),
            new TwigFunction('marketplace_vat_rate', [$this->pricing, 'vatRateFor']),
            new TwigFunction('marketplace_vat_rates', [$this->pricing, 'vatRatesOf']),
        ];
    }
}
