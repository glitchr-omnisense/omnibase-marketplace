<?php

namespace Tests\Base\Marketplace\Shopify;

use Base\Marketplace\Shopify\Api\GraphQL;
use Base\Marketplace\Shopify\Api\ShopifyApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The transport, against MockHttpClient. Every wait is asserted rather than
 * served: the sleep is injected, so these run instantly.
 */
final class GraphQLTest extends TestCase
{
    /** @var float[] */
    private array $slept = [];

    private function client(MockHttpClient $http): GraphQL
    {
        $this->slept = [];

        return new GraphQL($http, null, function (float $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    private function query(GraphQL $graphql): array
    {
        return $graphql->query('https://example.myshopify.com/admin/api/2026-07/graphql.json', ['X-Shopify-Access-Token' => 'shpat_secret'], '{ shop { name } }');
    }

    public function testItReturnsTheDataObject(): void
    {
        $graphql = $this->client(new MockHttpClient([
            new MockResponse(json_encode(['data' => ['shop' => ['name' => 'Demo']]])),
        ]));

        self::assertSame(['shop' => ['name' => 'Demo']], $this->query($graphql));
        self::assertSame([], $this->slept);
    }

    /** The token goes in a header and must never come back out in a message. */
    public function testTheAccessTokenIsSentAsAHeader(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = $options['headers'] ?? [];

            return new MockResponse(json_encode(['data' => []]));
        });

        $this->query($this->client($http));

        self::assertContains('X-Shopify-Access-Token: shpat_secret', $seen);
    }

    public function testAThrottledAnswerIsRetried(): void
    {
        $graphql = $this->client(new MockHttpClient([
            new MockResponse(json_encode(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])),
            new MockResponse(json_encode(['data' => ['shop' => ['name' => 'Demo']]])),
        ]));

        self::assertSame(['shop' => ['name' => 'Demo']], $this->query($graphql));
        self::assertCount(1, $this->slept, 'it should have waited once before retrying');
    }

    public function testItGivesUpAfterThreeThrottles(): void
    {
        $throttled = static fn () => new MockResponse(json_encode(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]));
        $graphql = $this->client(new MockHttpClient([$throttled(), $throttled(), $throttled()]));

        $this->expectException(ShopifyApiException::class);
        $this->query($graphql);
    }

    public function testA429IsRetried(): void
    {
        $graphql = $this->client(new MockHttpClient([
            new MockResponse('', ['http_code' => 429]),
            new MockResponse(json_encode(['data' => ['ok' => true]])),
        ]));

        self::assertSame(['ok' => true], $this->query($graphql));
        self::assertCount(1, $this->slept);
    }

    /** 401 is a wrong token; retrying it only wastes time. */
    public function testA401IsNotRetried(): void
    {
        $graphql = $this->client(new MockHttpClient([new MockResponse('', ['http_code' => 401])]));

        $this->expectException(ShopifyApiException::class);

        try {
            $this->query($graphql);
        } finally {
            self::assertSame([], $this->slept);
        }
    }

    /**
     * The cost bucket, not a call counter. A query that drains it should be
     * followed by a wait proportional to what it took.
     */
    public function testItWaitsWhenTheCostBucketRunsLow(): void
    {
        $graphql = $this->client(new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['ok' => true],
                'extensions' => ['cost' => ['throttleStatus' => [
                    'maximumAvailable' => 1000.0, 'currentlyAvailable' => 100.0, 'restoreRate' => 50.0,
                ]]],
            ])),
        ]));

        $this->query($graphql);

        // (200 floor - 100 left) / 50 per second = 2 seconds.
        self::assertSame([2.0], $this->slept);
    }

    public function testItDoesNotWaitWhenTheBucketIsHealthy(): void
    {
        $graphql = $this->client(new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['ok' => true],
                'extensions' => ['cost' => ['throttleStatus' => [
                    'maximumAvailable' => 1000.0, 'currentlyAvailable' => 990.0, 'restoreRate' => 50.0,
                ]]],
            ])),
        ]));

        $this->query($graphql);

        self::assertSame([], $this->slept);
    }

    public function testANonJsonBodyIsAnError(): void
    {
        $graphql = $this->client(new MockHttpClient([new MockResponse('<html>maintenance</html>')]));

        $this->expectException(ShopifyApiException::class);
        $this->expectExceptionMessage('not JSON');
        $this->query($graphql);
    }

    /**
     * A mutation that fails on business grounds answers 200 with an empty
     * result and a populated userErrors. A caller checking only the status
     * code would read that as success.
     */
    public function testUserErrorsAreRaised(): void
    {
        $graphql = $this->client(new MockHttpClient([]));

        $this->expectException(ShopifyApiException::class);
        $this->expectExceptionMessage('variantId: Variant does not exist');

        $graphql->assertNoUserErrors('draftOrderCreate', [
            'draftOrder' => null,
            'userErrors' => [['field' => ['variantId'], 'message' => 'Variant does not exist']],
        ]);
    }

    public function testNoUserErrorsPassesThrough(): void
    {
        $graphql = $this->client(new MockHttpClient([]));
        $result = ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/1'], 'userErrors' => []];

        self::assertSame($result, $graphql->assertNoUserErrors('draftOrderCreate', $result));
    }
}
