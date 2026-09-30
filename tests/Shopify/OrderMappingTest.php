<?php

namespace Tests\Base\Marketplace\Shopify;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Shopify\Checkout\DraftOrderMapper;
use Base\Marketplace\Shopify\Export\OrderMapper;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * The two order mappers. Both are pure, so an order mocked to answer the
 * handful of getters they read is enough - no database, no Shopify.
 *
 * What is really being tested is that this bundle's prices survive the trip.
 * That is the reason draft orders were chosen over a Storefront cart: a cart
 * would make Shopify recompute, and any order carrying a discount, a fee, a
 * shipping charge or VAT would be charged a different amount from the one
 * Transaction::$totalAmount recorded.
 */
final class OrderMappingTest extends TestCase
{
    private function order(
        int $unitPrice = 1250,
        int $quantity = 2,
        int $shipping = 0,
        int $discount = 0,
        int $vat = 0,
        string $currency = 'EUR',
        bool $shippable = true,
    ): Order {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(null);
        $product->method('isShippable')->willReturn($shippable);
        $product->method('getEAN')->willReturn(null);
        $product->method('__toString')->willReturn('Enamel Mug');

        $item = $this->createMock(OrderItem::class);
        $item->method('getProduct')->willReturn($product);
        $item->method('getQuantity')->willReturn($quantity);
        $item->method('getUnitPrice')->willReturn($unitPrice);

        $method = $this->createMock(PaymentMethod::class);
        $method->method('getGatewayFactory')->willReturn('stripe');

        $order = $this->createMock(Order::class);
        $order->method('getReference')->willReturn('ABC-1234-XYZ');
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getItems')->willReturn(new ArrayCollection([$item]));
        $order->method('getShippingCharge')->willReturn($shipping);
        $order->method('getDiscountCharge')->willReturn($discount);
        $order->method('getVatCharge')->willReturn($vat);
        $order->method('getTotalPaid')->willReturn($unitPrice * $quantity + $shipping - $discount);
        $order->method('getPaymentMethod')->willReturn($method);
        $order->method('getCustomer')->willReturn(null);
        $order->method('getShippingMethod')->willReturn(null);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getPaidAt')->willReturn(new \DateTime('2026-09-19T10:00:00+00:00'));

        return $order;
    }

    // --- draft orders, the checkout side -----------------------------------

    public function testADraftOrderCarriesOurOwnUnitPrice(): void
    {
        $input = (new DraftOrderMapper())->map($this->order());

        self::assertCount(1, $input['lineItems']);
        self::assertSame(2, $input['lineItems'][0]['quantity']);
        self::assertSame('12.50', $input['lineItems'][0]['originalUnitPriceWithCurrency']['amount']);
        self::assertSame('EUR', $input['lineItems'][0]['originalUnitPriceWithCurrency']['currencyCode']);
    }

    /** Without a synced catalogue, a custom line item - no variant needed. */
    public function testALineItemIsCustomWhenThereIsNoLink(): void
    {
        $input = (new DraftOrderMapper())->map($this->order());

        self::assertArrayNotHasKey('variantId', $input['lineItems'][0]);
        self::assertSame('Enamel Mug', $input['lineItems'][0]['title']);
    }

    public function testTheReferenceTravelsAsACustomAttribute(): void
    {
        $input = (new DraftOrderMapper())->map($this->order());

        self::assertSame(
            [['key' => 'marketplace_reference', 'value' => 'ABC-1234-XYZ']],
            $input['customAttributes'],
        );
    }

    /**
     * The whole reason for choosing draft orders: these three do not survive a
     * Storefront cart.
     */
    public function testShippingAndDiscountAreCarriedAcross(): void
    {
        $input = (new DraftOrderMapper())->map($this->order(shipping: 495, discount: 200));

        self::assertSame('4.95', $input['shippingLine']['priceWithCurrency']['amount']);
        self::assertSame(2.0, $input['appliedDiscount']['value']);
        self::assertSame('FIXED_AMOUNT', $input['appliedDiscount']['valueType']);
    }

    public function testNoShippingLineWhenNothingIsCharged(): void
    {
        $input = (new DraftOrderMapper())->map($this->order(shipping: 0));

        self::assertArrayNotHasKey('shippingLine', $input);
        self::assertArrayNotHasKey('appliedDiscount', $input);
    }

    public function testTagsAreAppliedWhenConfigured(): void
    {
        $input = (new DraftOrderMapper())->map($this->order(), 'example.myshopify.com', ['shop']);

        self::assertSame(['shop'], $input['tags']);
    }

    /** A currency with no minor unit must not be multiplied by a hundred. */
    public function testAZeroDecimalCurrency(): void
    {
        $input = (new DraftOrderMapper())->map($this->order(unitPrice: 1250, currency: 'JPY'));

        self::assertSame('1250', $input['lineItems'][0]['originalUnitPriceWithCurrency']['amount']);
    }

    // --- order push, the fulfilment side ------------------------------------

    public function testAPushedOrderArrivesPaid(): void
    {
        ['order' => $input] = (new OrderMapper())->map($this->order(shipping: 495));

        self::assertSame('PAID', $input['financialStatus']);
        self::assertSame('ABC-1234-XYZ', $input['sourceIdentifier']);
        self::assertSame('SALE', $input['transactions'][0]['kind']);
        self::assertSame('SUCCESS', $input['transactions'][0]['status']);
        self::assertSame('29.95', $input['transactions'][0]['amountSet']['shopMoney']['amount']);
        self::assertSame('stripe', $input['transactions'][0]['gateway']);
    }

    public function testVatIsDeclaredAsAlreadyIncluded(): void
    {
        ['order' => $input] = (new OrderMapper())->map($this->order(vat: 500));

        self::assertTrue($input['taxesIncluded']);
        self::assertSame('5.00', $input['taxLines'][0]['priceSet']['shopMoney']['amount']);
    }

    public function testNoReceiptIsSentByShopify(): void
    {
        ['options' => $options] = (new OrderMapper())->map($this->order());

        self::assertFalse($options['sendReceipt']);
        self::assertFalse($options['sendFulfillmentReceipt']);
    }

    public function testTheProcessedAtIsWhenWeWerePaid(): void
    {
        ['order' => $input] = (new OrderMapper())->map($this->order());

        self::assertSame('2026-09-19T10:00:00+00:00', $input['processedAt']);
    }

    public function testALineItemKnowsWhetherItShips(): void
    {
        ['order' => $input] = (new OrderMapper())->map($this->order(shippable: false));

        self::assertFalse($input['lineItems'][0]['requiresShipping']);
    }
}
