<?php

namespace Base\Market\Twig;

use Base\Market\Service\Cart;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the shop's chrome needs from any page: the number of lines waiting in
 * the visitor's carts, for the badge next to the cart link.
 */
final class MarketTwigExtension extends AbstractExtension
{
    public function __construct(private readonly Cart $cart)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('market_cart_count', [$this->cart, 'count']),
        ];
    }
}
