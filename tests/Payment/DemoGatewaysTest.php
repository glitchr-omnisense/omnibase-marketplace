<?php

namespace Tests\Base\Marketplace\Payment;

use Base\Marketplace\DependencyInjection\MarketplaceExtension;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Payment\DevGateway;
use Base\Marketplace\Payment\ManualGateway;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Gateway;
use Omnitrade\GatewayFactoryInterface;
use Omnitrade\GatewayInterface;
use Omnitrade\Registry;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paying in a demonstration (glitchr/omnibase's `demo` environment, which
 * never runs in debug): the "dev" gateway is there - and there only, besides
 * debug -, the gateways of glitchr/omnitrade are not: no real provider is
 * offered at the checkout nor reached by a return page.
 */
final class DemoGatewaysTest extends TestCase
{
    private function container(string $environment, bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.environment', $environment);
        (new MarketplaceExtension())->load([[]], $container);

        return $container;
    }

    private function registered(ContainerBuilder $container): bool
    {
        return $container->hasDefinition(DevGateway::class)
            && $container->getDefinition(DevGateway::class)->hasTag('marketplace.payment_gateway')
            && !$container->getDefinition(DevGateway::class)->hasTag('container.excluded');
    }

    public function testTheDevGatewayIsRegisteredInDemoWithoutDebugAndNowhereElseWithoutIt(): void
    {
        $this->assertTrue($this->registered($this->container('demo', false)), 'the demonstration');
        $this->assertTrue($this->registered($this->container('dev', true)), 'debug, as before');
        $this->assertFalse($this->registered($this->container('prod', false)), 'never in production');
        $this->assertFalse($this->registered($this->container('test', false)));
    }

    public function testItTakesAnOrderInDemoAndNotInProduction(): void
    {
        $order = $this->createMock(Order::class);
        $method = $this->createMock(PaymentMethod::class);

        $this->assertTrue((new DevGateway(false, 'demo'))->supports($order, $method));
        $this->assertFalse((new DevGateway(false, 'prod'))->supports($order, $method));
        $this->assertFalse((new DevGateway(false))->supports($order, $method), 'an environment nobody named is not the demonstration');
        $this->assertTrue((new DevGateway(true, 'dev'))->supports($order, $method));
    }

    /** Two methods as a shop has them: a card through an omnitrade gateway, the demonstration's "dev". */
    private function methods(): array
    {
        return [
            $this->createConfiguredMock(PaymentMethod::class, ['getGatewayParameters' => [], 'getSlug' => 'card', 'getGatewayFactory' => 'card']),
            $this->createConfiguredMock(PaymentMethod::class, ['getGatewayParameters' => [], 'getSlug' => 'dev', 'getGatewayFactory' => 'dev']),
            $this->createConfiguredMock(PaymentMethod::class, ['getGatewayParameters' => [], 'getSlug' => 'transfer', 'getGatewayFactory' => 'manual']),
        ];
    }

    private function registry(string $environment, bool $debug): PaymentGatewayRegistry
    {
        if (!class_exists(Registry::class)) {
            self::markTestSkipped('Requires glitchr/omnitrade.');
        }

        // An omnitrade gateway named "card", as omnitrade.gateways would configure a Stripe one: it takes a purchase, nothing more.
        $action = new class implements ActionInterface {
            public function supports(Request $request): bool
            {
                return $request instanceof Purchase;
            }

            public function execute(Request $request): void
            {
                throw new \LogicException('No payment is made in this test.');
            }
        };
        $factory = new class($action) implements GatewayFactoryInterface {
            public function __construct(private readonly ActionInterface $action)
            {
            }

            public function getName(): string
            {
                return 'stub';
            }

            public function create(array $options = []): GatewayInterface
            {
                return new Gateway('stub', 'Stub', [$this->action]);
            }
        };
        $omnitrade = new OmnitradeGateways(new Registry([$factory], ['card' => ['factory' => 'stub', 'options' => []]]), $this->createMock(UrlGeneratorInterface::class));

        return new PaymentGatewayRegistry([new ManualGateway(), new DevGateway($debug, $environment)], $omnitrade, $environment);
    }

    private function slugs(array $methods): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->getSlug(), $methods);
    }

    public function testInDemoTheRealGatewaysAreNotOfferedAndTheDevOneIs(): void
    {
        $registry = $this->registry('demo', false);
        $order = $this->createConfiguredMock(Order::class, ['getCurrency' => 'EUR']);

        $this->assertSame(['dev', 'transfer'], $this->slugs($registry->usableFor($order, $this->methods())));
        $this->assertNull($registry->get('card'), 'nor reached by a return page or a webhook');
        $this->assertNotContains('card', $registry->names(), 'nor offered to a payment method in the back office');
        $this->assertContains('dev', $registry->names());
    }

    public function testInProductionItIsTheOtherWayRound(): void
    {
        $registry = $this->registry('prod', false);
        $order = $this->createConfiguredMock(Order::class, ['getCurrency' => 'EUR']);

        $this->assertSame(['card', 'transfer'], $this->slugs($registry->usableFor($order, $this->methods())));
        $this->assertNotNull($registry->get('card'));
        $this->assertContains('card', $registry->names());
    }
}
