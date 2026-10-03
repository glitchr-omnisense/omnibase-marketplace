<?php

namespace Tests\Base\Marketplace\Payment;

use Base\Marketplace\DependencyInjection\MarketplaceExtension;
use Base\Marketplace\Payment\DevGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** The "dev" gateway: in the container in debug only, and saying no outside it all the same. */
final class DevGatewayTest extends TestCase
{
    private function container(bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        (new MarketplaceExtension())->load([[]], $container);

        return $container;
    }

    public function testRegisteredInDebugOnly(): void
    {
        $debug = $this->container(true);
        $this->assertTrue($debug->hasDefinition(DevGateway::class));
        $this->assertTrue($debug->getDefinition(DevGateway::class)->hasTag('marketplace.payment_gateway'));

        $prod = $this->container(false);
        $this->assertFalse($prod->hasDefinition(DevGateway::class) && !$prod->getDefinition(DevGateway::class)->hasTag('container.excluded'));
    }

    public function testNamedDevAndPaidAtOnce(): void
    {
        $this->assertSame('dev', DevGateway::name());
        $order = $this->createMock(\Base\Marketplace\Entity\Order::class);
        $method = $this->createMock(\Base\Marketplace\Entity\Order\Method\PaymentMethod::class);
        $this->assertTrue((new DevGateway(true))->supports($order, $method));
        $this->assertFalse((new DevGateway(false))->supports($order, $method));
    }
}
