<?php

namespace Tests\Base\Market\Shopify;

use Base\Market\Entity\Order;
use Base\Market\Event\PaymentCancelledEvent;
use Base\Market\Service\Checkout;
use Base\Market\Shopify\Catalogue\InventorySynchronizer;
use Base\Market\Shopify\Catalogue\ProductMapper;
use Base\Market\Shopify\Catalogue\ProductSynchronizer;
use Base\Market\Shopify\Webhook\ShopifyWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * What a webhook does, with Checkout and the synchroniser mocked. No kernel,
 * no database - the split between this class and the controller is what makes
 * that possible.
 */
final class ShopifyWebhookHandlerTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function handler(
        ?Checkout $checkout = null,
        ?ProductSynchronizer $products = null,
        ?InventorySynchronizer $inventory = null,
        ?Order $order = null,
        bool $catalogue = true,
        bool $checkoutEnabled = true,
    ): ShopifyWebhookHandler {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($order);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $products ??= $this->createMock(ProductSynchronizer::class);
        $products->method('currency')->willReturn('EUR');

        return new ShopifyWebhookHandler(
            $entityManager,
            $checkout ?? $this->createMock(Checkout::class),
            new ProductMapper(),
            $products,
            $inventory ?? $this->createMock(InventorySynchronizer::class),
            $catalogue,
            $checkoutEnabled,
        );
    }

    /**
     * An unknown topic must answer successfully. Shopify deletes a
     * subscription after eight hours of continuous non-2xx, so a 500 here is
     * a way to lose the topics that do matter.
     */
    public function testAnUnknownTopicIsIgnoredWithoutTouchingAnything(): void
    {
        $checkout = $this->createMock(Checkout::class);
        $checkout->expects(self::never())->method('confirm');
        $checkout->expects(self::never())->method('cancel');

        $result = $this->handler($checkout)->handle('customers/data_request', ['id' => 1]);

        self::assertSame(['ignored' => true, 'topic' => 'customers/data_request'], $result);
    }

    public function testOrdersPaidConfirmsTheMatchingOrder(): void
    {
        $order = $this->order('ABC-1234-XYZ');

        $checkout = $this->createMock(Checkout::class);
        $checkout->expects(self::once())->method('confirm');

        $result = $this->handler($checkout, order: $order)->handle('orders/paid', $this->fixture('orders_paid.webhook.json'));

        self::assertSame(['confirmed' => 'ABC-1234-XYZ'], $result);
    }

    /**
     * The handler does not deduplicate; Checkout::confirm() is the idempotence
     * point. This documents that division: two deliveries mean two calls, and
     * that is safe.
     */
    public function testASecondDeliveryStillCallsConfirm(): void
    {
        $order = $this->order('ABC-1234-XYZ');

        $checkout = $this->createMock(Checkout::class);
        $checkout->expects(self::exactly(2))->method('confirm');

        $handler = $this->handler($checkout, order: $order);
        $payload = $this->fixture('orders_paid.webhook.json');

        $handler->handle('orders/paid', $payload);
        $handler->handle('orders/paid', $payload);
    }

    /** Somebody else's order, sold through Shopify directly. Not an error. */
    public function testAnUnknownOrderIsIgnored(): void
    {
        $checkout = $this->createMock(Checkout::class);
        $checkout->expects(self::never())->method('confirm');

        $result = $this->handler($checkout, order: null)->handle('orders/paid', $this->fixture('orders_paid.webhook.json'));

        self::assertTrue($result['ignored']);
    }

    public function testOrdersCancelledCancels(): void
    {
        $order = $this->order('ABC-1234-XYZ');

        $checkout = $this->createMock(Checkout::class);
        // The event is final: a real one, not a double.
        $checkout->expects(self::once())->method('cancel')->willReturnCallback(fn ($order, $transaction) => new PaymentCancelledEvent($order, $transaction));

        $result = $this->handler($checkout, order: $order)->handle('orders/cancelled', $this->fixture('orders_paid.webhook.json'));

        self::assertSame(['cancelled' => 'ABC-1234-XYZ'], $result);
    }

    public function testProductsUpdateGoesThroughTheRestEntryPoint(): void
    {
        $products = $this->createMock(ProductSynchronizer::class);
        $products->expects(self::once())->method('synchronize')->willReturn('updated');
        $products->expects(self::once())->method('flush');

        $result = $this->handler(products: $products)->handle('products/update', $this->fixture('product.webhook.json'));

        self::assertSame(1, $result['synchronized']);
        self::assertSame(['updated' => 1], $result['results']);
    }

    /** Never delete: retire. */
    public function testProductsDeleteDiscontinues(): void
    {
        $products = $this->createMock(ProductSynchronizer::class);
        $products->expects(self::once())
            ->method('discontinue')
            ->with('gid://shopify/Product/8472913847')
            ->willReturn(1);

        $result = $this->handler(products: $products)->handle('products/delete', $this->fixture('product.webhook.json'));

        self::assertSame(['discontinued' => 1], $result);
    }

    public function testInventoryLevelsUpdateIsDelegated(): void
    {
        $inventory = $this->createMock(InventorySynchronizer::class);
        $inventory->expects(self::once())->method('handle')->willReturn(true);

        $result = $this->handler(inventory: $inventory)->handle('inventory_levels/update', $this->fixture('inventory_levels_update.webhook.json'));

        self::assertSame(['handled' => true], $result);
    }

    /** A role that is switched off does nothing, quietly. */
    public function testCatalogueTopicsAreIgnoredWhenTheRoleIsOff(): void
    {
        $products = $this->createMock(ProductSynchronizer::class);
        $products->expects(self::never())->method('synchronize');

        $result = $this->handler(products: $products, catalogue: false)->handle('products/update', $this->fixture('product.webhook.json'));

        self::assertSame(['ignored' => true], $result);
    }

    public function testCheckoutTopicsAreIgnoredWhenTheRoleIsOff(): void
    {
        $checkout = $this->createMock(Checkout::class);
        $checkout->expects(self::never())->method('confirm');

        $result = $this->handler($checkout, checkoutEnabled: false)->handle('orders/paid', $this->fixture('orders_paid.webhook.json'));

        self::assertSame(['ignored' => true], $result);
    }

    private function order(string $reference): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getReference')->willReturn($reference);
        $order->method('getTransactions')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([
            (new \Base\Market\Entity\Order\Transaction())->setWebhook('gid://shopify/DraftOrder/990011'),
        ]));

        return $order;
    }
}
