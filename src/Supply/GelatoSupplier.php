<?php

namespace Base\Marketplace\Supply;

use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Supply\Model\SupplyLine;
use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyProduct;
use Base\Marketplace\Supply\Model\SupplyQuote;
use Base\Marketplace\Supply\Model\SupplyResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gelato, print on demand: cards, mugs, tote bags, textile, photo books,
 * made near their recipient. Its Order API v4 (https://order.gelatoapis.com,
 * the key in X-API-KEY): an order has one shipping address, items naming a
 * productUid and the files to print (public addresses Gelato fetches), and
 * is sent launched ("order") or as a "draft" confirmed later.
 *
 *     marketplace:
 *         supply:
 *             gelato: { api_key: '%env(GELATO_API_KEY)%', webhook_secret: '%env(GELATO_WEBHOOK_SECRET)%' }
 *
 * A Product's supplierReference is the productUid
 * ("cards_pf_a5_pt_350-gsm-coated-silk_cl_4-4_ver").
 *
 * The webhook (order_status_updated) is checked against webhook_secret when
 * one is set: Gelato sends what you configure as the endpoint's
 * authorization header; compared to it as given.
 */
class GelatoSupplier implements SupplierInterface
{
    public const ORDER_API = 'https://order.gelatoapis.com';
    public const PRODUCT_API = 'https://product.gelatoapis.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%marketplace.supply.gelato.api_key%')] private readonly ?string $apiKey = null,
        #[Autowire('%marketplace.supply.gelato.webhook_secret%')] private readonly ?string $webhookSecret = null,
        #[Autowire('%marketplace.supply.gelato.catalog%')] private readonly string $catalog = 'cards',
    ) {
    }

    public static function name(): string
    {
        return 'gelato';
    }

    public function isConfigured(): bool
    {
        return null !== $this->apiKey && '' !== $this->apiKey;
    }

    public function products(?string $query = null): array
    {
        $found = $this->call('POST', self::PRODUCT_API.'/v3/catalogs/'.rawurlencode($this->catalog).'/products:search', ['limit' => 50] + (null !== $query && '' !== $query ? ['attributeFilters' => new \stdClass(), 'query' => $query] : []));

        return array_map(static fn (array $p) => new SupplyProduct((string) ($p['productUid'] ?? ''), (string) ($p['title'] ?? $p['productUid'] ?? ''), $p), array_values(array_filter((array) ($found['products'] ?? []), 'is_array')));
    }

    public function quote(SupplyOrder $order): SupplyQuote
    {
        $answer = $this->call('POST', self::ORDER_API.'/v4/orders:quote', [
            'orderReferenceId' => $order->reference,
            'customerReferenceId' => $order->customerReference ?? $order->reference,
            'currency' => $order->currency,
            'allowMultipleQuotes' => false,
            'recipient' => $this->address($order),
            'products' => array_map(static fn (SupplyLine $l) => ['itemReferenceId' => $l->reference, 'productUid' => $l->productReference, 'quantity' => $l->quantity, 'files' => self::files($l)], $order->lines),
        ]);
        $quote = $answer['quotes'][0] ?? [];
        $products = 0;
        foreach ((array) ($quote['products'] ?? []) as $product) {
            $products += self::minor($product['price'] ?? 0);
        }
        $method = $quote['shipmentMethods'][0] ?? [];

        return new SupplyQuote($products, self::minor($method['price'] ?? 0), strtoupper((string) ($method['currency'] ?? $order->currency)), isset($method['minDeliveryDays']) ? (int) $method['minDeliveryDays'] : null, isset($method['maxDeliveryDays']) ? (int) $method['maxDeliveryDays'] : null, isset($method['shipmentMethodUid']) ? (string) $method['shipmentMethodUid'] : null, $answer);
    }

    public function submit(SupplyOrder $order, bool $draft = false): SupplyResult
    {
        $answer = $this->call('POST', self::ORDER_API.'/v4/orders', array_filter([
            'orderType' => $draft ? 'draft' : 'order',
            'orderReferenceId' => $order->reference,
            'customerReferenceId' => $order->customerReference ?? $order->reference,
            'currency' => $order->currency,
            'items' => array_map(static fn (SupplyLine $l) => ['itemReferenceId' => $l->reference, 'productUid' => $l->productReference, 'files' => self::files($l), 'quantity' => $l->quantity], $order->lines),
            'shipmentMethodUid' => $order->shippingMethod,
            'shippingAddress' => $this->address($order),
            'metadata' => $order->metadata ? array_map(static fn ($k, $v) => ['key' => (string) $k, 'value' => (string) $v], array_keys($order->metadata), $order->metadata) : null,
        ], static fn ($v) => null !== $v));

        return $this->result($answer);
    }

    public function confirm(string $reference): SupplyResult
    {
        return $this->result($this->call('PATCH', self::ORDER_API.'/v4/orders/'.rawurlencode($reference), ['orderType' => 'order']));
    }

    public function fetch(string $reference): SupplyResult
    {
        return $this->result($this->call('GET', self::ORDER_API.'/v4/orders/'.rawurlencode($reference)));
    }

    public function cancel(string $reference): SupplyResult
    {
        $this->call('POST', self::ORDER_API.'/v4/orders/'.rawurlencode($reference).':cancel');

        return new SupplyResult('gelato', $reference, SupplyStatus::CANCELLED);
    }

    public function notify(string $body, array $headers = []): ?SupplyResult
    {
        if (null !== $this->webhookSecret && '' !== $this->webhookSecret) {
            $given = '';
            foreach ($headers as $name => $value) {
                if ('authorization' === strtolower((string) $name)) {
                    $given = (string) (\is_array($value) ? ($value[0] ?? '') : $value);
                }
            }
            if (!hash_equals($this->webhookSecret, $given) && !hash_equals('Bearer '.$this->webhookSecret, $given)) {
                throw new SupplyException('gelato', 'The webhook is not Gelato\'s: its authorization does not match.');
            }
        }
        $event = json_decode($body, true);
        if (!\is_array($event) || !isset($event['orderId']) || !str_starts_with((string) ($event['event'] ?? ''), 'order_')) {
            return null;
        }

        return $this->result(['id' => $event['orderId']] + $event);
    }

    /** Gelato's order (or its status event) as a SupplyResult. */
    public function result(array $order): SupplyResult
    {
        $tracking = null;
        foreach ((array) ($order['items'] ?? []) as $item) {
            foreach ((array) ($item['fulfillments'] ?? []) as $fulfillment) {
                $tracking ??= \is_array($fulfillment) && !empty($fulfillment['trackingCode']) ? $fulfillment : null;
            }
        }
        $tracking ??= \is_array($order['shipment'] ?? null) ? $order['shipment'] : null;

        return new SupplyResult(
            'gelato',
            (string) ($order['id'] ?? ''),
            self::status((string) ($order['fulfillmentStatus'] ?? ''), (string) ($order['orderType'] ?? 'order')),
            isset($tracking['trackingCode']) ? (string) $tracking['trackingCode'] : null,
            isset($tracking['trackingUrl']) ? (string) $tracking['trackingUrl'] : null,
            isset($tracking['shipmentMethodName']) ? (string) $tracking['shipmentMethodName'] : null,
            isset($order['comment']) ? (string) $order['comment'] : null,
            isset($order['orderReferenceId']) ? (string) $order['orderReferenceId'] : null,
            $order,
        );
    }

    public static function status(string $fulfillment, string $orderType = 'order'): SupplyStatus
    {
        return match ($fulfillment) {
            'draft' => SupplyStatus::DRAFT,
            'created', 'uploading', 'pending_approval', 'pending_personalization', 'not_connected', 'on_hold' => 'draft' === $orderType ? SupplyStatus::DRAFT : SupplyStatus::SUBMITTED,
            'passed' => SupplyStatus::ACCEPTED,
            'in_production', 'printed' => SupplyStatus::IN_PRODUCTION,
            'shipped', 'in_transit' => SupplyStatus::SHIPPED,
            'delivered' => SupplyStatus::DELIVERED,
            'canceled' => SupplyStatus::CANCELLED,
            'failed', 'returned' => SupplyStatus::FAILED,
            default => 'draft' === $orderType ? SupplyStatus::DRAFT : SupplyStatus::SUBMITTED,
        };
    }

    /** @return list<array{type: string, url: string}> the first file the front ("default"), the second the back, then the inside */
    private static function files(SupplyLine $line): array
    {
        $types = ['default', 'back', 'inside'];
        $files = [];
        foreach (array_values($line->files) as $i => $url) {
            $files[] = ['type' => $types[$i] ?? 'default', 'url' => $url];
        }

        return $files;
    }

    /** @return array<string, string> */
    private function address(SupplyOrder $order): array
    {
        $r = $order->recipient;
        if (!$r->isComplete()) {
            throw new SupplyException('gelato', 'The recipient\'s address is incomplete.');
        }

        return array_filter([
            'companyName' => $r->company,
            'firstName' => $r->firstName(),
            'lastName' => $r->lastName() ?: $r->firstName(),
            'addressLine1' => $r->street[0],
            'addressLine2' => $r->street[1] ?? null,
            'city' => $r->city,
            'postCode' => $r->postcode,
            'state' => $r->state,
            'country' => strtoupper($r->country),
            'email' => $r->email,
            'phone' => $r->phone,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    private static function minor(mixed $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $url, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new SupplyException('gelato', 'No Gelato API key (marketplace.supply.gelato.api_key).');
        }
        try {
            $response = $this->http->request($method, $url, ['headers' => ['X-API-KEY' => $this->apiKey, 'Accept' => 'application/json'], 'timeout' => 30] + (null !== $body ? ['json' => $body] : []));
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            throw new SupplyException('gelato', 'Gelato did not answer: '.$e->getMessage(), $e);
        }
        if ($status >= 400) {
            throw new SupplyException('gelato', (string) ($data['message'] ?? sprintf('HTTP %d', $status)));
        }

        return \is_array($data) ? $data : [];
    }
}
