<?php

namespace Base\Market\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Base\Market\Enum\Barcode;
use Base\Market\Enum\OrderState;
use Base\Market\Enum\PaymentState;
use Base\Market\Enum\ProductAvailability;
use Base\Market\Enum\ShippingRate;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class MarketExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    /** The bundle's enums, registered as the Doctrine column types its entities name. */
    public const ENUMS = [OrderState::class, PaymentState::class, ProductAvailability::class, ShippingRate::class, Barcode::class];

    public function getConfiguration(array $config, ContainerBuilder $container): MarketConfiguration
    {
        return new MarketConfiguration();
    }

    /**
     * Two things the host would otherwise have to declare by hand:
     *
     *   - the enum column types, registered with DBAL the way base-bundle
     *     registers its own (a type discovered late is a column missing from
     *     the schema);
     *   - this bundle's attribute directory (#[OrderReference]), added to the
     *     paths base-bundle's AttributeReader scans.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // Named the way base-bundle names its own (EnumType::getStaticName()),
        // so the #[ORM\Column(type: 'order_state')] of the entities match.
        $types = [];
        foreach (self::ENUMS as $enum) {
            $types[$enum::getStaticName()] = $enum;
        }
        $container->prependExtensionConfig('doctrine', ['dbal' => ['types' => $types]]);
        $container->prependExtensionConfig('base', ['attributes' => ['paths' => [\dirname(__DIR__) . '/Attribute']]]);

        // Doctrine's auto_mapping maps exactly <bundle>/src/Entity, so the
        // Shopify subtree's own entity is invisible to it. Declared here and
        // only when the integration is switched on: a host that does not use
        // Shopify gets no extra table, not even an empty one.
        if ($this->shopifyEnabled($container)) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['MarketShopify' => [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => \dirname(__DIR__) . '/Shopify/Entity',
                'prefix' => 'Base\\Market\\Shopify\\Entity',
                'alias' => 'MarketShopify',
            ]]]]);
        }
    }

    /**
     * Whether market.shopify.enabled is a literal true somewhere in the raw,
     * unprocessed configuration.
     *
     * Raw on purpose: prepend() runs before processing, and reading the value
     * as written is what keeps this honest. An env placeholder is still the
     * string "%env(...)%" at this point, so only a real boolean matches - see
     * the note on the node in MarketConfiguration.
     */
    private function shopifyEnabled(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('market') as $config) {
            if (true === ($config['shopify']['enabled'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Tag every gateway, the application's included: the instanceof rule
        // in config/services.php only reaches services defined in that file.
        $container->registerForAutoconfiguration(\Base\Market\Payment\PaymentGatewayInterface::class)
            ->addTag('market.payment_gateway');
        // Asked by Pricing whether an order is sold without VAT.
        $container->registerForAutoconfiguration(\Base\Market\Pricing\VatExemptionInterface::class)
            ->addTag('market.vat_exemption');

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        $processor = new Processor();
        $configuration = new MarketConfiguration();
        $config = $processor->processConfiguration($configuration, $configs);

        // market.default_currency, market.gateways, market.gateways.<slug>...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());

        // The optional Shopify integration, in its own file so that nothing
        // about the container above changes when it is off. It must be loaded
        // here rather than from services.php, which is read before the
        // configuration exists and can therefore never see a setting.
        //
        // The transport check is the same kind of guard as the omnipay/stripe
        // one in services.php: symfony/http-client is the host's dependency,
        // not this bundle's. Both names are checked because the contracts
        // package arrives transitively far more often than the implementation.
        if (true === ($config['shopify']['enabled'] ?? false)
            && interface_exists(\Symfony\Contracts\HttpClient\HttpClientInterface::class)
            && class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
            $loader->load('shopify.php');
        }
    }
}
