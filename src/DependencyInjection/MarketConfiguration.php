<?php

namespace Base\Market\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class MarketConfiguration extends AbstractBaseConfiguration
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
                ->booleanNode('guest_cart')->defaultFalse()
                    ->info('Whether a visitor who is not signed in may fill a cart.')->end()
                // Per-gateway settings, keyed by the payment method's slug:
                //     market:
                //         gateways:
                //             stripe: { secret: '%env(STRIPE_SECRET)%' }
                // read by PaymentMethod::getGatewayParameters().
                ->arrayNode('gateways')
                    ->useAttributeAsKey('slug')
                    ->variablePrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
