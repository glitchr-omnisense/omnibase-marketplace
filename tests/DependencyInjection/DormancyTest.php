<?php

namespace Tests\Base\Marketplace\DependencyInjection;

use Base\Marketplace\DependencyInjection\MarketplaceExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The contract: with marketplace.shopify absent, this bundle is what it was.
 *
 * These are the tests to run first when anything in the Shopify subtree
 * changes. Everything else here tests that the integration works; these test
 * that it is not there.
 */
final class DormancyTest extends TestCase
{
    /**
     * The real services of the container, ignoring the abstract markers
     * Symfony leaves behind for every excluded path (Base\Marketplace\Entity,
     * Base\Marketplace\Enum and now Base\Marketplace\Shopify all get one). Those
     * carry container.excluded, hold no class file and are removed at compile
     * time - their presence is evidence the exclusion worked, not a leak.
     *
     * @return string[]
     */
    private function realServices(ContainerBuilder $container): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->hasTag('container.excluded')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * ShopifyController is the one accepted piece of residue, and it is the
     * same one StripeController already leaves: routes are collected by
     * reflecting over src/Controller/Client, independently of the container,
     * so the class has to stay loadable. Every Shopify dependency it takes is
     * nullable, and every action 404s without them.
     */
    private const ALLOWED_WHEN_OFF = ['Base\Marketplace\Controller\Client\ShopifyController'];

    public function testNoShopifyServiceWhenTheConfigIsAbsent(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([[]], $container);

        foreach ($this->realServices($container) as $id) {
            if (\in_array($id, self::ALLOWED_WHEN_OFF, true)) {
                continue;
            }

            self::assertStringNotContainsString('Shopify', $id, sprintf('%s should not be defined when Shopify is off', $id));
        }
    }

    public function testNoShopifyServiceWhenExplicitlyDisabled(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([['shopify' => ['enabled' => false]]], $container);

        foreach ($this->realServices($container) as $id) {
            if (\in_array($id, self::ALLOWED_WHEN_OFF, true)) {
                continue;
            }

            self::assertStringNotContainsString('Shopify', $id);
        }
    }

    /**
     * The subtree is excluded from config/services.php's recursive load. If
     * this marker ever disappears, the exclude() line has gone and every
     * Shopify class is being registered again.
     */
    public function testTheSubtreeIsExcludedFromTheMainLoad(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([[]], $container);

        self::assertTrue($container->hasDefinition('Base\Marketplace\Shopify'));
        self::assertTrue($container->getDefinition('Base\Marketplace\Shopify')->hasTag('container.excluded'));
    }

    /**
     * The controller survives with its Shopify arguments unfilled - the
     * mechanism the whole route residue depends on.
     */
    public function testTheControllerKeepsItsNullableArgumentsWhenOff(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([[]], $container);

        self::assertTrue($container->hasDefinition('Base\Marketplace\Controller\Client\ShopifyController'));

        $constructor = new \ReflectionMethod(\Base\Marketplace\Controller\Client\ShopifyController::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && str_contains($type->getName(), 'Shopify')) {
                self::assertTrue($type->allowsNull(), sprintf('$%s must be nullable', $parameter->getName()));
                self::assertTrue($parameter->isDefaultValueAvailable(), sprintf('$%s must have a default', $parameter->getName()));
            }
        }
    }

    public function testTheSubtreeIsWiredWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([['shopify' => ['enabled' => true]]], $container);

        self::assertTrue(
            $container->hasDefinition(\Base\Marketplace\Shopify\Checkout\ShopifyGateway::class),
            'The gateway should be defined once the integration is switched on',
        );
        self::assertTrue($container->hasDefinition(\Base\Marketplace\Shopify\Api\AdminApi::class));
        self::assertTrue($container->hasDefinition(\Base\Marketplace\Shopify\Catalogue\ProductSynchronizer::class));
    }

    /**
     * The gateway must be tagged like any other, or PaymentGatewayRegistry
     * never sees it. It is defined in a different file from the instanceof
     * rule, so this is worth asserting rather than assuming.
     */
    public function testTheGatewayIsTaggedAsAPaymentGateway(): void
    {
        $container = new ContainerBuilder();
        (new MarketplaceExtension())->load([['shopify' => ['enabled' => true]]], $container);

        $autoconfigured = $container->getAutoconfiguredInstanceof();
        self::assertArrayHasKey(\Base\Marketplace\Payment\PaymentGatewayInterface::class, $autoconfigured);
        self::assertArrayHasKey('marketplace.payment_gateway', $autoconfigured[\Base\Marketplace\Payment\PaymentGatewayInterface::class]->getTags());
    }

    /**
     * No mapping means no table. This is what keeps the schema of a host that
     * does not use Shopify byte-identical.
     */
    public function testNoDoctrineMappingWhenDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MarketplaceExtension());
        $container->loadFromExtension('marketplace', []);
        (new MarketplaceExtension())->prepend($container);

        foreach ($container->getExtensionConfig('doctrine') as $config) {
            self::assertArrayNotHasKey('MarketplaceShopify', $config['orm']['mappings'] ?? []);
        }
    }

    public function testTheEntityIsMappedWhenEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MarketplaceExtension());
        $container->loadFromExtension('marketplace', ['shopify' => ['enabled' => true]]);
        (new MarketplaceExtension())->prepend($container);

        $mappings = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $mappings += $config['orm']['mappings'] ?? [];
        }

        self::assertArrayHasKey('MarketplaceShopify', $mappings);
        self::assertSame('Base\\Marketplace\\Shopify\\Entity', $mappings['MarketplaceShopify']['prefix']);
        self::assertStringEndsWith('/Shopify/Entity', $mappings['MarketplaceShopify']['dir']);
    }

    /**
     * An env placeholder is still an unresolved string at compile time, so it
     * must NOT read as "on". If this ever fails, every host that merely
     * declared the env var would silently gain the integration.
     */
    public function testAnEnvPlaceholderDoesNotEnableTheIntegration(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new MarketplaceExtension());
        $container->loadFromExtension('marketplace', ['shopify' => ['shop_domain' => '%env(SHOPIFY_SHOP_DOMAIN)%']]);
        (new MarketplaceExtension())->prepend($container);

        foreach ($container->getExtensionConfig('doctrine') as $config) {
            self::assertArrayNotHasKey('MarketplaceShopify', $config['orm']['mappings'] ?? []);
        }
    }
}
