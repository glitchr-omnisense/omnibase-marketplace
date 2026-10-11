<?php

namespace Tests\Base\Marketplace\Http;

use Tests\Base\Marketplace\MarketplaceKernelTestCase;

/**
 * The mention after a price (@Marketplace/client/_price.html.twig): "HT" or
 * "TTC" when a template asks for it, none when it says false, the site's
 * rule (marketplace.price_tax) when it says nothing.
 */
final class PriceMentionTest extends MarketplaceKernelTestCase
{
    /** @param array<string, mixed> $with */
    private function price(array $with): string
    {
        return self::getContainer()->get('twig')->render('@Marketplace/client/_price.html.twig', $with + ['amount' => 13000, 'currency' => 'EUR']);
    }

    public function testATemplateSaysWhatFollowsThePrice(): void
    {
        $translator = self::getContainer()->get('translator');
        $excl = $this->price(['price_tax' => 'excl']);
        self::assertStringContainsString('<span class="marketplace-price-tax">'.$translator->trans('product.price_excl_vat', [], 'marketplace').'</span>', $excl);
        self::assertMatchesRegularExpression('/130[,.]00/', $excl);
        self::assertStringContainsString('<span class="marketplace-price-tax">'.$translator->trans('product.price_incl_vat', [], 'marketplace').'</span>', $this->price(['price_tax' => 'incl']));
        self::assertStringNotContainsString('marketplace-price-tax', $this->price(['price_tax' => false]), 'false: no mention, whatever the site\'s rule');
        self::assertStringNotContainsString('marketplace-price-tax', $this->price(['price_tax' => null]), 'null: none either');
    }

    public function testLeftOutTheSitesRuleApplies(): void
    {
        $rule = self::getContainer()->getParameter('marketplace.price_tax');
        self::assertContains($rule, [null, 'excl', 'incl']);
        self::assertSame($rule, self::getContainer()->get('twig')->getFunction('marketplace_price_tax')->getCallable()());
        $html = $this->price([]);
        if (null === $rule) {
            self::assertStringNotContainsString('marketplace-price-tax', $html);
        } else {
            self::assertStringContainsString('<span class="marketplace-price-tax">'.self::getContainer()->get('translator')->trans('product.price_'.$rule.'_vat', [], 'marketplace').'</span>', $html);
        }
    }
}
