<?php

namespace Base\Marketplace\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class MarketplaceConfiguration extends AbstractBaseConfiguration
{
    /**
     * getTreeBuilder() memoises the builder while this method ADDS children to
     * it, so a second call would redeclare them - same guard as the admin,
     * wikidoc and forum configurations.
     */
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('default_currency')->defaultValue('EUR')
                    ->info('ISO 4217 code of prices without a currency of their own - or a made-up one for an in-game currency.')->end()
                ->integerNode('products_per_page')->min(1)->defaultValue(24)->end()
                ->integerNode('orders_per_page')->min(1)->defaultValue(20)->end()
                ->integerNode('cart_max_quantity')->min(1)->defaultValue(99)
                    ->info('How many of one product a cart line may hold.')->end()
                ->scalarNode('default_gateway')->defaultValue('stripe')->info('The gateway checkout offers first.')->end()
                ->booleanNode('guest_cart')->defaultFalse()
                    ->info('Whether a visitor who is not signed in may fill a cart.')->end()
                ->scalarNode('admin_role')->defaultValue('ROLE_SUPERADMIN')
                    ->info('The role (or permission) every marketplace screen of the back office requires: the creators by default.')->end()
                // Currencies whose rate from default_currency is always kept,
                // besides those the shop uses (ExchangeRates::refresh()).
                ->arrayNode('forex')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('targets')->scalarPrototype()->end()->defaultValue([])
                            ->info('ISO 4217 codes, e.g. [JPY, USD]: one Fixer call refreshes them all.')->end()
                    ->end()
                ->end()
                // Per-gateway settings, keyed by the payment method's slug:
                //     marketplace:
                //         gateways:
                //             stripe: { secret: '%env(STRIPE_SECRET)%' }
                // read by PaymentMethod::getGatewayParameters().
                ->arrayNode('gateways')
                    ->useAttributeAsKey('slug')
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
                // Who parcels leave from, for the carriers (glitchr/omnibus): Shipping::sender().
                ->arrayNode('shipping')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('sender')->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('name')->defaultValue('')->end()
                                ->scalarNode('company')->defaultNull()->end()
                                ->arrayNode('street')->scalarPrototype()->end()->defaultValue([])->end()
                                ->scalarNode('postcode')->defaultValue('')->end()
                                ->scalarNode('city')->defaultValue('')->end()
                                ->scalarNode('country')->defaultValue('FR')->end()
                                ->scalarNode('email')->defaultNull()->end()
                                ->scalarNode('phone')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                // The page of a brand (Entity\Brand::__toLink()): an application route taking {slug}.
                ->scalarNode('brand_route')->defaultNull()
                    ->info('The route of a brand\'s page, given its slug; null: brands have no page.')->end()
                // Sold to adults only (Service\AgeGate): the age by country or language, the notice.
                ->arrayNode('age_gate')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('default')->min(0)->defaultValue(18)->info('The age asked where nothing more precise is set.')->end()
                        ->arrayNode('ages')->useAttributeAsKey('key')->integerPrototype()->end()->defaultValue([])
                            ->info('By language ("ja") or country ("JP"): {ja: 20, JP: 20}. A country wins over a language.')->end()
                        ->scalarNode('notice')->defaultNull()
                            ->info('The health notice shown with restricted products, e.g. "L\'abus d\'alcool est dangereux pour la santé, à consommer avec modération." (loi Évin); a translation key works too.')->end()
                        ->booleanNode('site')->defaultFalse()->info('Ask on every page, not only before a restricted product or taxon.')->end()
                    ->end()
                ->end()
                // Business quotes (Entity\Quote, Service\QuoteToOrder).
                ->arrayNode('quotes')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('store')->defaultNull()->info('Slug of the store a quote\'s order is made in when neither the quote nor its products name one.')->end()
                        ->integerNode('validity')->min(1)->defaultValue(30)->info('Days a quote sent holds when no date is set.')->end()
                        ->scalarNode('recipient')->defaultNull()->info('Who is told of a request (default: the base notifier\'s technical recipient).')->end()
                    ->end()
                ->end()
                // A platform holding the catalogue (glitchr/omnitrade): Catalogue\PlatformSynchronizer.
                ->arrayNode('catalogue')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('source')->defaultNull()
                            ->info('The omnitrade gateway the catalogue is read from (stripe, shopify, woocommerce...); null: kept in the back office.')->end()
                        ->scalarNode('store')->defaultNull()
                            ->info('Slug of the store the products read are filed in.')->end()
                        ->arrayNode('owned_fields')->scalarPrototype()->end()
                            ->defaultValue(['title', 'description', 'price', 'stock', 'availability', 'identifiers', 'brand', 'attributes'])
                            ->info('What the platform writes here; anything else (taxa, pairings, the age gate) stays the site\'s.')->end()
                        ->booleanNode('inventory')->defaultTrue()->info('Read the stock from the platform when it counts it (FetchInventory).')->end()
                    ->end()
                ->end()
                // Lists of wishes (Entity\Wishlist\*, Wishlist\*): gifts, births, funds.
                ->arrayNode('wishlist')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('lookup')->scalarPrototype()->end()->defaultValue(['amazon', 'web'])
                            ->info('The omnitrade gateways asked, in order, to read a pasted address and to write its affiliate link.')->end()
                        ->scalarNode('payout_gateway')->defaultValue('stripe')
                            ->info('The omnitrade gateway whose connected accounts the contributions are paid to.')->end()
                        ->floatNode('fee_rate')->min(0)->max(1)->defaultValue(0.03)->info('The platform\'s fee on a contribution: a share of the gift...')->end()
                        ->integerNode('fee_fixed')->min(0)->defaultValue(0)->info('...plus this, in minor units.')->end()
                        ->integerNode('minimum')->min(1)->defaultValue(500)->info('The smallest contribution, in minor units.')->end()
                        ->integerNode('reservation_days')->min(1)->defaultValue(30)->info('Days a reservation holds before its object goes back on the list.')->end()
                        ->integerNode('price_ttl')->min(60)->defaultValue(86400)->info('Seconds after which a price read from a shop is read again.')->end()
                    ->end()
                ->end()
                // Referrals (Entity\Sales\Referral\*, Service\Referrals).
                ->arrayNode('referral')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('cooling_days')->min(0)->defaultValue(14)
                            ->info('Days the referee\'s first order must hold (the withdrawal period) before the referrer is rewarded.')->end()
                        ->integerNode('monthly_cap')->min(0)->defaultValue(10)->info('Rewards a referrer may earn in a calendar month.')->end()
                        ->arrayNode('referee')->addDefaultsIfNotSet()->info('What a new member gets on signing up through a code.')
                            ->children()
                                ->enumNode('type')->values([null, 'coupon', 'credit'])->defaultNull()->info('null: nothing.')->end()
                                ->scalarNode('scope')->defaultValue('scope-user')->info('A coupon: the code of the discount scope adapter that ties it to its owner.')->end()
                                ->scalarNode('action')->defaultNull()->info('A coupon: the code of the discount action adapter (a percentage, a fixed amount).')->end()
                                ->floatNode('value')->defaultValue(0)->info('A coupon: what the action takes off (0.2 for 20 %, or minor units).')->end()
                                ->scalarNode('validity')->defaultValue('+3 months')->end()
                                ->scalarNode('prefix')->defaultValue('REF')->end()
                                ->scalarNode('label')->defaultValue('Referral')->end()
                                ->scalarNode('credit_kind')->defaultNull()->info('Credits: their kind.')->end()
                                ->integerNode('credit_quantity')->min(0)->defaultValue(0)->end()
                            ->end()
                        ->end()
                        ->arrayNode('referrer')->addDefaultsIfNotSet()->info('What the referrer gets once the referee\'s order has held.')
                            ->children()
                                ->enumNode('type')->values([null, 'coupon', 'credit'])->defaultNull()->info('null: nothing.')->end()
                                ->scalarNode('scope')->defaultValue('scope-user')->info('A coupon: the code of the discount scope adapter that ties it to its owner.')->end()
                                ->scalarNode('action')->defaultNull()->info('A coupon: the code of the discount action adapter (a percentage, a fixed amount).')->end()
                                ->floatNode('value')->defaultValue(0)->info('A coupon: what the action takes off (0.2 for 20 %, or minor units).')->end()
                                ->scalarNode('validity')->defaultValue('+3 months')->end()
                                ->scalarNode('prefix')->defaultValue('REF')->end()
                                ->scalarNode('label')->defaultValue('Referral')->end()
                                ->scalarNode('credit_kind')->defaultNull()->info('Credits: their kind.')->end()
                                ->integerNode('credit_quantity')->min(0)->defaultValue(0)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                // What is made to order (Supply\SupplierInterface, Service\Supply).
                ->arrayNode('supply')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('gelato')->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('api_key')->defaultNull()->end()
                                ->scalarNode('webhook_secret')->defaultNull()->info('What Gelato sends as its webhook\'s authorization header.')->end()
                                ->scalarNode('catalog')->defaultValue('cards')->info('The catalogue products() searches.')->end()
                            ->end()
                        ->end()
                        ->arrayNode('offline')->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('email')->defaultNull()->info('The partner workshop\'s address: the briefs go there.')->end()
                                ->scalarNode('name')->defaultNull()->end()
                                ->integerNode('link_ttl')->min(3600)->defaultValue(7776000)->info('Seconds the signed link of a brief stays valid (90 days).')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                // Plans and subscriptions (Enum\ProductKind::PLAN, Service\Entitlements, Service\Subscriptions).
                ->arrayNode('plans')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('reminder_days')->min(0)->defaultValue(7)
                            ->info('Days before a right ends when its holder is told (Event\EntitlementEndingEvent).')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
