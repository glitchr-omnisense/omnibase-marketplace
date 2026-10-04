<?php

namespace Base\Marketplace\Wishlist;

use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Model\Product;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\FetchProduct;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An address pasted into a list, read: the omnitrade gateways named in
 * marketplace.wishlist.lookup are asked in order (amazon before web: the
 * shop's own API before its page's markup), the first that knows the product
 * answers. The affiliate link is asked the same way - a gateway may write
 * it without being able to read the product (Amazon with its tag alone).
 */
class ProductLookup
{
    /** @param list<string> $gateways */
    public function __construct(
        private readonly OmnitradeGateways $bridges,
        #[Autowire('%marketplace.wishlist.lookup%')] private readonly array $gateways = ['amazon', 'web'],
    ) {
    }

    /** @return array{product: Product, gateway: string}|null */
    public function lookup(string $url): ?array
    {
        foreach ($this->gateways as $name) {
            try {
                // Through the bridges: the keys typed in the back office (Clés API) are applied.
                $gateway = $this->bridges->get($name)?->gateway();
                if (null === $gateway || !$gateway->supports(FetchProduct::class)) {
                    continue;
                }
                $product = $gateway->fetchProduct($url);
            } catch (OmnitradeException) {
                continue; // not configured, or the page did not answer: the next one
            }
            if (null !== $product) {
                return ['product' => $product, 'gateway' => $name];
            }
        }

        return null;
    }

    /** The address with the site's affiliate tag, when one of the gateways has a programme for it. */
    public function affiliateLink(string $url): ?string
    {
        if (!class_exists(AffiliateLink::class)) {
            return null;
        }
        foreach ($this->gateways as $name) {
            try {
                $gateway = $this->bridges->get($name)?->gateway();
                if (null === $gateway || !$gateway->supports(AffiliateLink::class)) {
                    continue;
                }
                $link = $gateway->execute(new AffiliateLink($url))->getUrl();
            } catch (OmnitradeException) {
                continue;
            }
            if (null !== $link) {
                return $link;
            }
        }

        return null;
    }
}
