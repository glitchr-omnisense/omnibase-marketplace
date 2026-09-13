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
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        $processor = new Processor();
        $configuration = new MarketConfiguration();
        $config = $processor->processConfiguration($configuration, $configs);

        // market.default_currency, market.gateways, market.gateways.<slug>...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
