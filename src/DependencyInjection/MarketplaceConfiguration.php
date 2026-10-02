<?php

namespace Base\Marketplace\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
                ->append($this->shopifyNode())
            ->end()
        ->end();

        return $treeBuilder;
    }

    /**
     * The optional Shopify integration - a shop's catalogue read in, a hosted
     * checkout, paid orders pushed out for fulfilment. Everything under
     * src/Shopify is left out of the container unless `enabled` is true here.
     *
     * `enabled` MUST be a literal boolean, never an env placeholder: the
     * extension branches on it at COMPILE time, where "%env(FOO)%" is still
     * the unresolved string and every env var would therefore read as "on".
     * The secrets below are env placeholders as usual - an unset one resolves
     * to the empty string, which every consumer treats as "not configured"
     * and declines quietly, the way StripeGateway::supports() already does.
     */
    private function shopifyNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('shopify'))->getRootNode();

        $node
            ->canBeEnabled()
            ->children()
                ->scalarNode('shop_domain')->defaultValue('')
                    ->info('The myshopify.com domain, e.g. "example.myshopify.com".')->end()
                ->scalarNode('api_version')->defaultValue('2026-07')
                    ->info('Admin API version. Pinned, because mutation shapes change between versions.')->end()
                ->scalarNode('admin_token')->defaultValue('')
                    ->info('Admin API access token (shpat_...). A full-store credential: keep it in an env var.')->end()
                ->scalarNode('storefront_token')->defaultValue('')->end()
                ->scalarNode('webhook_secret')->defaultValue('')
                    ->info('The app\'s API secret key, used to verify X-Shopify-Hmac-Sha256.')->end()
                ->integerNode('timeout')->min(1)->defaultValue(15)->end()

                // Role (a): the hosted checkout.
                ->arrayNode('checkout')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('draft_order_tags')->scalarPrototype()->end()->defaultValue([])->end()
                    ->end()
                ->end()

                // Role (b): the catalogue read in from Shopify.
                ->arrayNode('catalogue')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('store')->defaultNull()
                            ->info('Slug of the Store synced products attach to. Cart::add() refuses a product without one.')->end()
                        ->scalarNode('merchant')->defaultNull()
                            ->info('Id of the member owning synced products; null leaves them unowned.')->end()
                        // Declared, not yet implemented. Product\Image goes
                        // through base-bundle's Flysystem uploader rather than
                        // holding a URL, so importing images means fetching
                        // and storing every one of them - a piece of work in
                        // its own right, and not one to smuggle into a
                        // catalogue sync. Setting this has no effect today.
                        ->booleanNode('images')->defaultFalse()
                            ->info('NOT YET IMPLEMENTED. Images are never fetched; product media stays whatever the shop set.')->end()
                        // Only these fields are ever written. Anything not listed -
                        // taxa, channels, owners, an application subclass's own
                        // columns - survives a sync untouched.
                        ->arrayNode('owned_fields')
                            ->scalarPrototype()->end()
                            ->defaultValue(['title', 'description', 'slug', 'price', 'stock', 'availability', 'identifiers', 'tags'])
                        ->end()
                    ->end()
                ->end()

                // Role (c): paid orders pushed out to Shopify.
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->booleanNode('only_shippable')->defaultTrue()
                            ->info('Skip orders made entirely of products that do not ship.')->end()
                        ->scalarNode('location')->defaultNull()
                            ->info('Shopify location GID inventory is attributed to.')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $node;
    }
}
