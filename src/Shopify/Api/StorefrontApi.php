<?php

namespace Base\Marketplace\Shopify\Api;

/**
 * The Storefront API, for the public catalogue and a Shopify-hosted cart.
 *
 * Present for completeness and used by nothing yet: the checkout gateway
 * takes the Draft Order route instead, because draft orders carry THIS
 * bundle's prices - the discounts, fees and taxes Pricing has just computed -
 * whereas a Storefront cart makes Shopify recalculate them and the two totals
 * drift apart on any order that is not plain.
 */
class StorefrontApi
{
    public function __construct(
        private readonly GraphQL $graphql,
        private readonly Endpoint $endpoint,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->endpoint->shopDomain && '' !== $this->endpoint->storefrontToken;
    }

    public function query(string $document, array $variables = []): array
    {
        if (!$this->isConfigured()) {
            throw new ShopifyApiException('The Shopify Storefront API is not configured: set marketplace.shopify.storefront_token.');
        }

        return $this->graphql->query(
            $this->endpoint->storefrontUrl(),
            ['X-Shopify-Storefront-Access-Token' => $this->endpoint->storefrontToken],
            $document,
            $variables,
            $this->endpoint->timeout,
        );
    }
}
