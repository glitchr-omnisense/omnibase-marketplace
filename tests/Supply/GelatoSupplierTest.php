<?php

namespace Tests\Base\Marketplace\Supply;

use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Supply\GelatoSupplier;
use Base\Marketplace\Supply\Model\SupplyLine;
use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyRecipient;
use Base\Marketplace\Supply\SupplyException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Gelato's Order API, on answers shaped as its documentation shows them: no real call. */
final class GelatoSupplierTest extends TestCase
{
    /** @var list<array{string, string, array, array}> */
    private array $calls = [];

    private function supplier(?string $key = 'key_test', ?string $secret = null): GelatoSupplier
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? 'null'), true) ?? [];
            $this->calls[] = [$method, $url, $body, $options];
            $order = ['id' => 'gel_1', 'orderType' => $body['orderType'] ?? 'order', 'orderReferenceId' => $body['orderReferenceId'] ?? 'CMD-1-1', 'fulfillmentStatus' => 'created'];

            return match (true) {
                'POST' === $method && str_ends_with($url, '/v4/orders') => new MockResponse(json_encode($order)),
                'PATCH' === $method => new MockResponse(json_encode(['orderType' => 'order', 'fulfillmentStatus' => 'passed'] + $order)),
                'GET' === $method && str_ends_with($url, '/v4/orders/gel_1') => new MockResponse(json_encode(['fulfillmentStatus' => 'shipped', 'items' => [['itemReferenceId' => '5', 'fulfillments' => [['trackingCode' => 'LA123FR', 'trackingUrl' => 'https://track.test/LA123FR', 'shipmentMethodName' => 'La Poste']]]]] + $order)),
                str_ends_with($url, ':cancel') => new MockResponse('{}'),
                str_ends_with($url, '/v4/orders:quote') => new MockResponse(json_encode(['quotes' => [['products' => [['itemReferenceId' => '5', 'price' => 42.5, 'currency' => 'EUR']], 'shipmentMethods' => [['shipmentMethodUid' => 'normal', 'price' => 6.9, 'currency' => 'EUR', 'minDeliveryDays' => 3, 'maxDeliveryDays' => 6]]]]])),
                default => new MockResponse(json_encode(['message' => 'Not found']), ['http_code' => 404]),
            };
        });

        return new GelatoSupplier($http, $key, $secret);
    }

    private function order(): SupplyOrder
    {
        return new SupplyOrder('CMD-1-1', [new SupplyLine('5', 'cards_pf_a5_pt_350-gsm-coated-silk_cl_4-4_ver', 50, ['https://site.test/front.pdf', 'https://site.test/back.pdf'], 'Faire-part')], new SupplyRecipient('Léa Martin', ['12 rue des Lilas', 'Bât. B'], '37000', 'Tours', 'fr', 'lea@example.org', '+33600000000'), 'EUR', null, '42');
    }

    public function testAnOrderIsSentLaunchedOrAsADraft(): void
    {
        $gelato = $this->supplier();
        self::assertSame('gelato', $gelato::name());
        self::assertTrue($gelato->isConfigured());

        $result = $gelato->submit($this->order(), true);
        self::assertSame('gel_1', $result->reference);
        self::assertSame(SupplyStatus::DRAFT, $result->status);
        self::assertSame('CMD-1-1', $result->orderReference);

        [$method, $url, $sent, $options] = $this->calls[0];
        self::assertSame('https://order.gelatoapis.com/v4/orders', $url);
        self::assertContains('X-API-KEY: key_test', $options['headers']);
        self::assertSame('draft', $sent['orderType']);
        self::assertSame('CMD-1-1', $sent['orderReferenceId']);
        self::assertSame('42', $sent['customerReferenceId']);
        self::assertSame(['itemReferenceId' => '5', 'productUid' => 'cards_pf_a5_pt_350-gsm-coated-silk_cl_4-4_ver', 'files' => [['type' => 'default', 'url' => 'https://site.test/front.pdf'], ['type' => 'back', 'url' => 'https://site.test/back.pdf']], 'quantity' => 50], $sent['items'][0]);
        self::assertSame(['firstName' => 'Léa', 'lastName' => 'Martin', 'addressLine1' => '12 rue des Lilas', 'addressLine2' => 'Bât. B', 'city' => 'Tours', 'postCode' => '37000', 'country' => 'FR', 'email' => 'lea@example.org', 'phone' => '+33600000000'], $sent['shippingAddress']);

        self::assertSame(SupplyStatus::ACCEPTED, $gelato->confirm('gel_1')->status);
        self::assertSame(['orderType' => 'order'], $this->calls[1][2]);
        self::assertSame(SupplyStatus::SUBMITTED, $this->supplier()->submit($this->order())->status);
    }

    public function testItsStateAndItsParcel(): void
    {
        $result = $this->supplier()->fetch('gel_1');
        self::assertSame(SupplyStatus::SHIPPED, $result->status);
        self::assertSame('LA123FR', $result->trackingNumber);
        self::assertSame('https://track.test/LA123FR', $result->trackingUrl);
        self::assertSame('La Poste', $result->carrier);
        self::assertSame(SupplyStatus::CANCELLED, $this->supplier()->cancel('gel_1')->status);

        $quote = $this->supplier()->quote($this->order());
        self::assertSame(4250, $quote->products);
        self::assertSame(690, $quote->shipping);
        self::assertSame(4940, $quote->total());
        self::assertSame(6, $quote->maxDays);
    }

    public function testTheWebhook(): void
    {
        $body = json_encode(['id' => 'os_1', 'event' => 'order_status_updated', 'orderId' => 'gel_1', 'orderReferenceId' => 'CMD-1-1', 'fulfillmentStatus' => 'in_production']);
        $result = $this->supplier()->notify($body);
        self::assertSame('gel_1', $result->reference);
        self::assertSame(SupplyStatus::IN_PRODUCTION, $result->status);
        self::assertNull($this->supplier()->notify(json_encode(['event' => 'catalog_product_stock_availability_updated'])));

        self::assertNotNull($this->supplier('key_test', 's3cret')->notify($body, ['Authorization' => ['s3cret']]));
        $this->expectException(SupplyException::class);
        $this->supplier('key_test', 's3cret')->notify($body, ['authorization' => 'wrong']);
    }

    public function testWithoutAKeyOrAnAddressNothingLeaves(): void
    {
        self::assertFalse($this->supplier(null)->isConfigured());
        try {
            $this->supplier()->submit(new SupplyOrder('X', [new SupplyLine('1', 'p')], new SupplyRecipient('Léa', [], '', '')));
            self::fail('No address.');
        } catch (SupplyException $e) {
            self::assertStringContainsString('incomplete', $e->getMessage());
        }
        self::assertSame([], $this->calls);

        $this->expectException(SupplyException::class);
        $this->supplier(null)->fetch('gel_1');
    }
}
