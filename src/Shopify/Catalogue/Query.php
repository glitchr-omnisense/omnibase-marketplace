<?php

namespace Base\Marketplace\Shopify\Catalogue;

/**
 * The GraphQL documents, kept together so that an API version bump is one
 * file to read rather than a grep across the subtree.
 */
final class Query
{
    /**
     * A page of products with their variants.
     *
     * first: 50 rather than the permitted 250 on purpose - this query is
     * costed by the number of nodes it can return (products x variants), and
     * 250 x 100 asks for a bucket most shops do not have. Tune upwards
     * against the real extensions.cost figures, not by guessing.
     */
    public const PRODUCTS = <<<'GRAPHQL'
        query Products($first: Int!, $after: String, $query: String) {
          products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id
              handle
              title
              descriptionHtml
              status
              tags
              updatedAt
              featuredMedia { ... on MediaImage { id image { url altText } } }
              variants(first: 100) {
                nodes {
                  id
                  title
                  sku
                  barcode
                  availableForSale
                  inventoryQuantity
                  price
                  compareAtPrice
                  image { url altText }
                  inventoryItem { id tracked }
                }
              }
            }
          }
        }
        GRAPHQL;

    /** Who am I talking to - the whole of marketplace:shopify:ping. */
    public const SHOP = <<<'GRAPHQL'
        query Shop {
          shop { name myshopifyDomain currencyCode ianaTimezone plan { displayName } }
        }
        GRAPHQL;

    public const WEBHOOK_SUBSCRIPTIONS = <<<'GRAPHQL'
        query Webhooks($first: Int!) {
          webhookSubscriptions(first: $first) {
            nodes { id topic endpoint { ... on WebhookHttpEndpoint { callbackUrl } } }
          }
        }
        GRAPHQL;

    public const WEBHOOK_CREATE = <<<'GRAPHQL'
        mutation WebhookCreate($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: { callbackUrl: $callbackUrl, format: JSON }) {
            webhookSubscription { id topic }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public const WEBHOOK_DELETE = <<<'GRAPHQL'
        mutation WebhookDelete($id: ID!) {
          webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors { field message }
          }
        }
        GRAPHQL;
}
