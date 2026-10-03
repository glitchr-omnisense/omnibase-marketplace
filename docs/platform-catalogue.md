---
title: A catalogue kept on a platform
order: 23
---

# A catalogue kept on a platform

The catalogue is kept in the back office by default. It may live on a
platform instead - Stripe's Products (no extra cost when Stripe already takes
the payments), a Shopify shop, a WooCommerce site: any glitchr/omnitrade
gateway answering `FetchProducts` (see glitchr/omnitrade's
`docs/catalogue.md`). The payments stay wherever the shop pays.

```yaml
# config/packages/omnitrade.yaml
omnitrade:
    gateways:
        shopify: { factory: shopify, options: { shop: '%env(SHOPIFY_SHOP)%', access_token: '%env(SHOPIFY_ADMIN_TOKEN)%', webhook_secret: '%env(SHOPIFY_WEBHOOK_SECRET)%' } }

# config/packages/marketplace.yaml
marketplace:
    catalogue:
        source: shopify      # null: the back office
        store: cave          # the store the products are filed in
        owned_fields: [title, description, price, stock, availability, identifiers, brand, attributes]
        inventory: true      # read the stock where the platform counts it
```

```
bin/console marketplace:catalogue:sync                 # what changed since the last change read
bin/console marketplace:catalogue:sync stripe --full   # everything; what is gone there is taken off sale here
bin/console marketplace:catalogue:sync --inventory     # the stock levels only (Shopify, WooCommerce; not Stripe)
bin/console marketplace:catalogue:sync --dry-run -v
```

Run it from the cron container (plan 0: cron + Messenger, no Scheduler).

## What is written

`Catalogue\PlatformSynchronizer`: one way, the platform to the site. A
platform product's first variant is the `Product`, its other variants
`Variant`s; its vendor or brand a `Brand` (found by name or created); its
key/value attributes the product's attributes **of the same code** - an
adapter must exist (an `AttributeSet` field): "custom.appellation" (a Shopify
metafield), "pa_appellation" (a WooCommerce attribute) and "appellation" (a
Stripe metadata) all fill `appellation`, the rest is left there -; its tags
`Feature`s; its SKU and barcode `Identifier`s; its price, stock and
availability.

- Only `owned_fields` are written: taxa, pairings, the age gate, the lots,
  the pictures chosen here are the site's.
- A variant whose owned fields hash as last time is not written at all.
- Nothing is deleted: a product gone (or no longer active) there is
  `DISCONTINUED` here with no stock, its link `orphaned`.
- A first import links a product already sold here under the same SKU.
- A product with the feature `platform-unmanaged` is never written.
- Pictures are not fetched (the uploader stores files, not URLs): they are
  added in the back office.

`Entity\Catalogue\PlatformLink` keeps, per variant, the gateway, the product's
and the variant's ids there, the counted item (a Shopify inventory item), the
SKU, a fingerprint and the platform's last change.

## Webhooks

The platform's product and stock webhooks go to the same address as its
payment webhooks, `/marketplace/{gateway}/webhook` (`PaymentController`): the
omnitrade gateway checks and reads them, and a catalogue event
(`Notification::isCatalogue()`) is dispatched as `CatalogueNotificationEvent`;
`Catalogue\CatalogueNotificationListener` brings the product in again, takes
it off sale when deleted, writes the stock - when that gateway is the
`catalogue.source`.

## From the former Shopify module

`src/Shopify` (its `ProductLink`, the `marketplace.shopify` configuration, the
`marketplace:shopify:*` commands, the draft-order checkout and the order push)
is gone: no application used it. Shopify's payments and platform orders are
`omnitrade/shopify`'s, its catalogue this page's. An application that had the
module's table keeps its links with:

```sql
INSERT INTO marketplace_platform_link (product_id, gateway, remoteProduct, remoteVariant, remoteItem, fingerprint, syncedAt, orphaned)
SELECT product_id, 'shopify', productGid, variantGid, inventoryItemId, '', NOW(), 0
FROM marketplaceShopifyProductLink WHERE product_id IS NOT NULL;
DROP TABLE marketplaceShopifyProductLink;
```
