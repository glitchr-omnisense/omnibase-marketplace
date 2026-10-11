<?php

namespace Base\Marketplace\Twig;

use Base\Marketplace\Service\Cart;
use Base\Marketplace\Service\Pricing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *   {{ marketplace_price_tax() }}                   the mention after a price (marketplace.price_tax): 'excl', 'incl' or null
 */
final class MarketplaceTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly Cart $cart,
        private readonly Pricing $pricing,
        #[Autowire('%marketplace.price_tax%')] private readonly ?string $priceTax = null,
    ) {
    }

    public function priceTax(): ?string
    {
        return $this->priceTax;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('marketplace_cart_count', [$this->cart, 'count']),
            new TwigFunction('marketplace_price_with_vat', [$this->pricing, 'priceWithVat']),
            new TwigFunction('marketplace_vat_rate', [$this->pricing, 'vatRateFor']),
            new TwigFunction('marketplace_vat_rates', [$this->pricing, 'vatRatesOf']),
            new TwigFunction('marketplace_price_tax', [$this, 'priceTax']),
        ];
    }
}
