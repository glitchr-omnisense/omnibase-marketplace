---
title: "Installation"
order: 10
---

# Installation

## Requirements

PHP 8.2 or later, Symfony 7.4 or 8 (config, dependency-injection, form,
http-kernel, security-bundle, lock), Doctrine ORM, omnisense/omnibase 3.x or
4.x, omnibridge/symfony 1.x, omnibase/admin, omnisense/omnistate with
omnistate/annuaire-entreprises and omnistate/vies (business customers' VAT
and company numbers), dompdf/dompdf (the invoices' PDF) and
horstoeko/zugferd (their Factur-X data).

Suggested: omnisense/omnitrade (payments: omnitrade/stripe,
omnitrade/paypal...), omnisense/omnibus (carriers: omnibus/mondial-relay,
omnibus/ups...), omnisense/omnibill with omnibill/email or omnibill/afnor
(sending the invoices), omnisense/omnisign (signing a quote in the page).

## The bundle

```bash
composer require omnibase/marketplace:dev-main
```

```php
// config/bundles.php
Base\Marketplace\MarketplaceBundle::class => ['all' => true],
```

## The routes

`MarketplaceBundle::getPath()` is the package root, so the path includes
`src/`:

```yaml
# config/routes.yaml
marketplace_controller:
    resource: "@MarketplaceBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

## The seller

Products, stores and orders point at
`Base\Marketplace\Model\MerchantInterface`, which only asks for
`getCompanyName()`; bind it to a class of the application:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        resolve_target_entities:
            Base\Marketplace\Model\MerchantInterface: App\Entity\User
```

## The schema and the assets

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
bin/console assets:install
```

## The configuration

Every key has a default ([Configuration reference](configuration.md)). The
shop's own:

```yaml
# config/packages/marketplace.yaml
marketplace:
    default_currency: EUR     # a store's currency wins over this
    products_per_page: 24
    orders_per_page: 20
    cart_max_quantity: 99     # per line; Product::getMaxQuantity() can lower it
    guest_cart: false
    quick_order: { enabled: true, link_ttl: 2592000 }
    gateways:                 # settings per payment method, keyed by its slug
        virement:
            currencies: [EUR] # keeps the method off orders in other currencies
            instructions: "IBAN FR76 ..., reference of the order in the label."
```

## The layout

The pages extend `layout1.html.twig` and fill its `content`, `aside`, `title`
and `stylesheets` blocks. Every class is `.marketplace-*`, driven by custom
properties on `.marketplace`; a store's page also carries
`.marketplace-store-<slug>`. A site overrides any template under
`templates/bundles/MarketplaceBundle/client/` (`_price`, `_product_media` and
`_banner` exist for that), calling the original with `@!Marketplace/client/...`.

## The cron

The commands that run on a schedule are given with their cron lines where
they are explained: `marketplace:subscriptions --sync` in
[Plans, subscriptions, rights and credits](plans.md),
`marketplace:referrals` in [Referrals](referrals.md),
`marketplace:wishlist:refresh-prices` in [Lists of wishes](wishlists.md);
every command is listed in the [reference](reference.md#commands).

## The demo

The repository holds a demo application under `example/app/` and a
`Dockerfile` that builds it: the `bundles.php`, `routes.yaml`,
`doctrine.yaml`, `security.yaml` and `marketplace.yaml` an application needs,
a gateway of its own (`src/Payment/DemoGateway.php`) and a user with a
username.
