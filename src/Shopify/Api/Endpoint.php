<?php

namespace Base\Marketplace\Shopify\Api;

/**
 * One Shopify shop: where it is, which API version to speak, and the two
 * tokens. A value object rather than five constructor arguments spread over
 * every service, so that serving several shops later is a change here and
 * nowhere else.
 *
 * isConfigured() is the "not set up" test every consumer makes before doing
 * anything - an unset env var resolves to '' and must degrade quietly, never
 * throw. Same shape as StripeGateway::supports() declining on an empty key.
 */
final class Endpoint
{
    public readonly string $shopDomain;
    public readonly string $apiVersion;
    public readonly string $adminToken;
    public readonly string $storefrontToken;
    public readonly string $webhookSecret;
    public readonly int $timeout;

    /**
     * Every argument is nullable, and that is not defensiveness.
     *
     * The settings arrive as %env(default::SHOPIFY_...)%, which is the shape
     * the Stripe entries already use in this bundle - and an UNSET variable
     * there resolves to null, not to an empty string. Declaring these
     * non-nullable would fail the container's type check on any host that had
     * turned the integration on before filling in its credentials, which is
     * precisely the moment somebody needs a readable error instead.
     *
     * They are normalised once, here, so that everything downstream can ask
     * isConfigured() and never think about it again.
     */
    public function __construct(
        ?string $shopDomain = '',
        ?string $apiVersion = '2026-07',
        ?string $adminToken = '',
        ?string $storefrontToken = '',
        ?string $webhookSecret = '',
        ?int $timeout = 15,
    ) {
        $this->shopDomain = trim((string) $shopDomain);
        $this->apiVersion = trim((string) $apiVersion) ?: '2026-07';
        $this->adminToken = trim((string) $adminToken);
        $this->storefrontToken = trim((string) $storefrontToken);
        $this->webhookSecret = trim((string) $webhookSecret);
        $this->timeout = $timeout ?: 15;
    }

    /** Whether there is enough here to call the Admin API at all. */
    public function isConfigured(): bool
    {
        return '' !== $this->shopDomain && '' !== $this->adminToken;
    }

    public function canVerifyWebhooks(): bool
    {
        return '' !== $this->webhookSecret;
    }

    /** The shop as Shopify names it in X-Shopify-Shop-Domain. */
    public function shop(): string
    {
        return $this->shopDomain;
    }

    public function adminUrl(): string
    {
        return sprintf('https://%s/admin/api/%s/graphql.json', $this->shopDomain, $this->apiVersion);
    }

    public function storefrontUrl(): string
    {
        return sprintf('https://%s/api/%s/graphql.json', $this->shopDomain, $this->apiVersion);
    }

    /** The admin URL of one resource, for an "open in Shopify" link. */
    public function adminLink(string $gid): string
    {
        $parts = explode('/', $gid);
        $id = end($parts);
        $type = strtolower((string) ($parts[\count($parts) - 2] ?? 'product'));

        return sprintf('https://%s/admin/%ss/%s', $this->shopDomain, $type, $id);
    }
}
