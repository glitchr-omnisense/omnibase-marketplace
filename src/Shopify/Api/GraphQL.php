<?php

namespace Base\Market\Shopify\Api;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST a GraphQL document, get the `data` back or a ShopifyApiException.
 *
 * Deliberately knows nothing about products, orders or carts: every document
 * is passed in. That is what lets the mappers stay pure and the tests run on
 * MockHttpClient with no Shopify at the other end.
 *
 * Two things it does know about:
 *
 *   - Shopify's Admin API is a leaky bucket costed per query, not a call
 *     counter. Every response reports the bucket in
 *     extensions.cost.throttleStatus; when it runs low this waits for it to
 *     refill rather than charging on and collecting a THROTTLED.
 *   - A THROTTLED answer is still retried, with backoff, because the cost of
 *     a query is only known after it has been made.
 *
 * The access token never reaches a log or an exception message: the header
 * array is built per request and nothing here interpolates it.
 */
class GraphQL
{
    /** Wait when fewer than this many points are left in the bucket. */
    private const COST_FLOOR = 200;
    private const MAX_ATTEMPTS = 3;

    /** @var callable(float): void */
    private $sleep;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?LoggerInterface $logger = null,
        ?callable $sleep = null,
    ) {
        // Injected so a test can assert the wait instead of serving it.
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> the `data` object
     *
     * @throws ShopifyApiException
     */
    public function query(string $url, array $headers, string $document, array $variables = [], int $timeout = 15): array
    {
        $attempt = 0;

        while (true) {
            ++$attempt;

            try {
                $response = $this->http->request('POST', $url, [
                    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
                    'body' => json_encode(['query' => $document, 'variables' => (object) $variables], \JSON_THROW_ON_ERROR),
                    'timeout' => $timeout,
                ]);

                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (HttpExceptionInterface|\JsonException $e) {
                // The token could be in neither, but be explicit about what we
                // pass on: the transport's message only.
                throw new ShopifyApiException('Shopify request failed: ' . $e->getMessage(), [], $e);
            }

            // 429 and 5xx are worth another go; 401/403/404 never are.
            if (429 === $status || $status >= 500) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }

                throw new ShopifyApiException(sprintf('Shopify returned HTTP %d after %d attempts.', $status, $attempt));
            }

            if ($status >= 400) {
                throw new ShopifyApiException(sprintf('Shopify returned HTTP %d.', $status));
            }

            $payload = json_decode($body, true);
            if (!\is_array($payload)) {
                throw new ShopifyApiException('Shopify returned a body that is not JSON.');
            }

            if (!empty($payload['errors'])) {
                if ($this->isThrottled($payload['errors']) && $attempt < self::MAX_ATTEMPTS) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }

                throw ShopifyApiException::fromErrors('Shopify rejected the query', $payload['errors']);
            }

            $this->respectBucket($payload);

            return $payload['data'] ?? [];
        }
    }

    /**
     * Raise a mutation's own userErrors. Shopify answers 200 with an empty
     * result and a populated userErrors when it refuses on business grounds -
     * an unknown variant, a price it will not take - so a caller that only
     * checks the HTTP status sees a silent success.
     */
    public function assertNoUserErrors(string $what, array $result): array
    {
        if (!empty($result['userErrors'])) {
            throw ShopifyApiException::fromErrors($what, $result['userErrors']);
        }

        return $result;
    }

    private function isThrottled(array $errors): bool
    {
        foreach ($errors as $error) {
            if ('THROTTLED' === ($error['extensions']['code'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Wait for the bucket to refill if this query drained it. */
    private function respectBucket(array $payload): void
    {
        $status = $payload['extensions']['cost']['throttleStatus'] ?? null;
        if (!\is_array($status)) {
            return;
        }

        $available = (float) ($status['currentlyAvailable'] ?? \PHP_INT_MAX);
        $restoreRate = (float) ($status['restoreRate'] ?? 0);
        if ($available >= self::COST_FLOOR || $restoreRate <= 0) {
            return;
        }

        $wait = ceil((self::COST_FLOOR - $available) / $restoreRate);
        $this->logger?->info('Shopify cost bucket low, waiting {seconds}s', ['seconds' => $wait, 'available' => $available]);
        ($this->sleep)($wait);
    }

    private function backoff(int $attempt): float
    {
        return 2 ** ($attempt - 1);
    }
}
