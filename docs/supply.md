---
title: Made to order
order: 90
---

# Made to order: suppliers

What the shop sells but does not make: cards, mugs, books printed on demand,
or anything a partner workshop makes. A product names its **supplier**; once
an order is paid its made-to-order lines are handed to their suppliers, each
recipient's apart, and followed until the parcel is delivered.

`Fulfilment` already names how an order is handed over in some shops; this
is `Supply`.

## Installation

The update adds two columns to the products' table (`supplier`,
`supplierReference`), two to the order items' (`recipient`,
`personalisation`) and one table (`marketplace_supply_job`): a migration.

```yaml
# config/packages/marketplace.yaml
marketplace:
    supply:
        gelato:
            api_key: '%env(GELATO_API_KEY)%'
            webhook_secret: '%env(GELATO_WEBHOOK_SECRET)%'   # what Gelato sends as its webhook's authorization header
            catalog: cards                                   # the catalogue products() searches
        offline:
            email: 'atelier@example.org'                     # the partner workshop: briefs go there
            name: 'Atelier Dupont'
            link_ttl: 7776000                                # seconds its signed links stay valid (90 days)
```

The application imports the bundle's client routes (it already does for the
cart): they include the workshop's page (`marketplace_supply_offline`) and
the suppliers' webhook (`/marketplace/supply/{supplier}/webhook`: give it to
Gelato as `https://<site>/marketplace/supply/gelato/webhook`).

## A product made to order

```php
$card->setSupplier('gelato')->setSupplierReference('cards_pf_a5_pt_350-gsm-coated-silk_cl_4-4_ver');   // Gelato's productUid
$menu->setSupplier('offline')->setSupplierReference('menu-a5-vergé');                                  // the workshop's own name for it
```

A variant without a supplier is made by its principal's.

## A line's recipient and files

```php
$item = new OrderItem($card, 50);
$item->setPersonalisation(['files' => [$frontPdfUrl, $backPdfUrl], 'monogram' => 'L&H']);   // print files as public addresses; any words
$item->setRecipient(['name' => 'Léa Martin', 'street' => ['12 rue des Lilas'], 'postcode' => '37000', 'city' => 'Tours', 'country' => 'FR']);
```

Without a recipient of its own a line goes to the order's shipping address.
Lines are grouped by supplier and by recipient: a card sent to each guest
is one job per guest, a batch for the host one job.

## What happens

`EventListener\SupplyListener` (on `OrderPaidEvent`) calls
`Service\Supply::dispatch($order)`: one `Entity\Order\SupplyJob` per group,
submitted to its supplier. A supplier that refuses or does not answer does
not undo the payment: its job is kept `failed` with the reason, and sent
again with `resubmit()`.

```php
$supply->jobsOf($order);          // the jobs, their status, their tracking
$supply->sync($job);              // asks the supplier
$supply->resubmit($job);          // a failed one, again
$supply->dispatch($order, true);  // as drafts...
$supply->confirm($job);           // ...launched later
$supply->cancel($job);
```

| Status | |
|---|---|
| `draft` | sent as a draft, not launched |
| `submitted` | sent, not answered yet |
| `accepted`, `in_production` | being made |
| `shipped` | left: `trackingNumber`, `trackingUrl`, `carrier` |
| `delivered`, `cancelled`, `failed` | final |

`SupplyJobChangedEvent` is dispatched each time a job moves: the
application tells the buyer. The bundle does not create a `Shipment` for a
supplier's parcel: its tracking is the job's.

## The suppliers

| Supplier | How |
|---|---|
| `gelato` | Gelato's Order API v4 (`https://order.gelatoapis.com`, `X-API-KEY`): `POST /v4/orders` (`orderType` `order` or `draft`, `items` with `productUid` and `files`, `shippingAddress`), `PATCH` to launch a draft, `GET`, `:cancel`, `/v4/orders:quote`; its `order_status_updated` webhook. One order per address. |
| `offline` | a brief by e-mail (`@Marketplace/email/supply_brief.html.twig`, on the application's `email.html.twig`) with a signed link to a page where the workshop accepts, says where it stands and gives the tracking. No account, nothing to install there. |

Another supplier is a class of the application implementing
`Supply\SupplierInterface` (`name()`, `isConfigured()`, `products()`,
`quote()`, `submit()`, `confirm()`, `fetch()`, `cancel()`, `notify()`):
autoconfigured, found by `Supply\SupplierRegistry`.

Gelato's calls are written from its public documentation and tested on
answers shaped as it shows them; no call was made with a real key. The
`products()` search (`product.gelatoapis.com/v3/catalogs/{catalog}/products:search`)
and the webhook's authorization check in particular are to be confirmed on
a first real exchange.
