<?php

namespace Base\Marketplace\DependencyInjection;

use Base\Marketplace\Payment\DevGateway;
use Base\Bundle\AbstractBaseExtension;
use Base\Marketplace\Enum\Barcode;
use Base\Marketplace\Enum\OrderState;
use Base\Marketplace\Enum\PaymentState;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Marketplace\Enum\ShippingRate;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class MarketplaceExtension extends AbstractBaseExtension implements PrependExtensionInterface
{
    /** The bundle's enums, registered as the Doctrine column types its entities name. */
    public const ENUMS = [OrderState::class, PaymentState::class, ProductAvailability::class, ShippingRate::class, Barcode::class];

    public function getConfiguration(array $config, ContainerBuilder $container): MarketplaceConfiguration
    {
        return new MarketplaceConfiguration();
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

        // Where an invoice stands on a glitchr/omnibill gateway (Invoice\Transmission\Entity\InvoiceFlow):
        // mapped only with the family - a site without it has no such table, and needs none.
        if (class_exists('Omnibill\\Registry')) {
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => ['MarketplaceInvoiceTransmission' => [
                'is_bundle' => false,
                'type' => 'attribute',
                'dir' => \dirname(__DIR__) . '/Invoice/Transmission/Entity',
                'prefix' => 'Base\\Marketplace\\Invoice\\Transmission\\Entity',
                'alias' => 'MarketplaceInvoiceTransmission',
            ]]]]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Tag every gateway, the application's included: the instanceof rule
        // in config/services.php only reaches services defined in that file.
        $container->registerForAutoconfiguration(\Base\Marketplace\Payment\PaymentGatewayInterface::class)
            ->addTag('marketplace.payment_gateway');
        // Asked by Pricing whether an order is sold without VAT.
        $container->registerForAutoconfiguration(\Base\Marketplace\Pricing\VatExemptionInterface::class)
            ->addTag('marketplace.vat_exemption');

        // Who makes what is made to order: the bundle's suppliers and the application's.
        $container->registerForAutoconfiguration(\Base\Marketplace\Supply\SupplierInterface::class)
            ->addTag('marketplace.supplier');

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.php');

        // The "dev" gateway - paid by nobody, at once - exists in debug, and in the
        // demonstration (glitchr/omnibase's `demo` environment, which never runs in debug): nowhere else.
        $debug = $container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug');
        $demo = $container->hasParameter('kernel.environment') && DevGateway::DEMO_ENVIRONMENT === $container->getParameter('kernel.environment');
        if ($debug || $demo) {
            $container->register(DevGateway::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->addTag('marketplace.payment_gateway');
        }

        $processor = new Processor();
        $configuration = new MarketplaceConfiguration();
        $config = $processor->processConfiguration($configuration, $configs);

        // marketplace.default_currency, marketplace.gateways, marketplace.gateways.<slug>...
        $this->setConfiguration($container, $config, $configuration->getTreeBuilder()->buildTree()->getName());
    }
}
