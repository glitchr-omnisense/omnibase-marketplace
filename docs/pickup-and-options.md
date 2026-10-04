---
title: Hand-overs, options, starting prices, files
order: 95
---

# Hand-overs, options, starting prices, files

What a shop that makes things - a caterer, a sign maker, a florist - adds to a
plain cart. Nothing here knows the trade: which dish may travel chilled is
omnibase/restaurant's business (`Service\ColdChain`), not the marketplace's.

## Pickup: how an order is handed over

`Entity\Order\Pickup` (table `marketplace_pickup`) goes with an order, one row
per order: the **mode** (`Enum\PickupMode`: `pickup` at the shop on a slot,
`delivery` brought nearby by the shop itself, `shipping` by a carrier), the
**day** and the **slot** wished ("12:00-12:30"), the time the shop gives
(`readyAt`), who (`contactName`, `email`, `phone`), where (`address`,
`postcode`, `city`, `country`), a note, a parcel's tracking number, and a
**token**: `/remise/{token}` (`marketplace_pickup`) follows it without an
account. Orders made together may share a token; the page shows them together.

"Fulfilment" already names this in some applications and `Supply` is what a
supplier makes to order ([Made to order](supply.md)): hence `Pickup`.

```yaml
marketplace:
    pickup:
        modes: [pickup, delivery]        # default: [pickup]; "shipping" for a carrier
        zip_codes: ['67000', '67100', '674*']   # where the shop delivers itself; a prefix ends with *
        slots: { from: '11:00', to: '19:00', step: 30 }
        notice: 60                       # minutes the shop needs before the first slot
        horizon: 14                      # days ahead
        timezone: 'Europe/Paris'         # the shop's clock; null: PHP's (omnibase sets it to the visitor's)
```

```php
$pickups->modes();                          // [PickupMode::PICKUP, PickupMode::DELIVERY]
$pickups->deliversTo('67400');              // true
$pickups->days();                           // the days still open
$pickups->slots($day);                      // ['11:00-11:30', '11:30-12:00', …], those gone left out

$pickup = $pickups->open($order, PickupMode::DELIVERY, $day, [
    'contactName' => 'Léa Martin', 'phone' => '06 …', 'slot' => '12:00-12:30',
    'address' => '12 rue des Lilas', 'postcode' => '67000', 'city' => 'Strasbourg',
]);                                         // CartException pickup.error.{mode,day,out_of_zone,address,placed}
$pickups->move($pickup, PickupStatus::READY);
$pickups->trackingUrl($pickup);
```

`open()` may be called again while the order is still a cart (the buyer
changes their mind); once placed it refuses. An application that filters the
modes itself passes `check: false` as the last argument.

| Status (`Enum\PickupStatus`) | |
|---|---|
| `checkout` | the order exists, its payment has not come |
| `received` | paid: set by `EventListener\PickupPaidListener` on `OrderPaidEvent` |
| `accepted`, `preparing`, `ready` | the shop's steps |
| `out_for_delivery` | a delivery or a parcel on its way (not a step of a pickup) |
| `done`, `cancelled`, `refused`, `refunded` | final |

Every move dispatches `Event\PickupChangedEvent` (`$pickup`, `$previous`):
the application writes to the customer, prints a ticket. The bundle sends no
mail of its own. `@Marketplace/client/_pickup.html.twig` shows one hand-over.

A `ShippingMethod` may now have **no carrier**: `gatewayName` is nullable
(`hasCarrier()`), for "collected at the workshop" priced like any method.

## Options: what the buyer chooses on a product

`Entity\Product\OptionGroup` and `Option` (tables
`marketplace_product_option_group`, `marketplace_product_option`): a choice
that is not another reference - a cooking, extras, a finish. A group takes
one option or several (`multiple`), between `minimum` and `maximum`; an
option may add to the price (`price`: smallest unit, **before VAT** like the
product's), be preselected (`default`) or unavailable. Labels have
translations (`labels: {en: …}`). A variant without groups offers its
principal's.

```php
$ramen->addOptionGroup((new OptionGroup('Cuisson des nouilles', minimum: 1))
    ->option('Fermes')->option('Normales', default: true)->option('Tendres'));
$ramen->addOptionGroup((new OptionGroup('Suppléments', multiple: true, maximum: 2))
    ->option('Œuf mollet', 150)->option('Chashu', 300, labels: ['en' => 'Braised pork']));

$selection = $options->select($ramen, [$egg->getId()]);   // Service\ProductOptions; CartException options.error.*
$selection->surcharge();                                  // 150
$cart->add($ramen, 2, [$egg->getId()]);                   // a line per product and choice
```

The line keeps a copy (`OrderItem::getOptions()`: `[{group, option,
group_label, label, price}]`, `getOptionsLabel()`, `getOptionsSurcharge()`)
and its unit price is the product's plus the surcharge
(`OrderItem::applyOptions()`), so discounts and VAT apply to the whole. The
product's page shows the groups inside its "add to cart" form
(`@Marketplace/client/_options.html.twig`, posting `options[<group>]`).
Back office: `OptionGroupCrudController` (Boutique › Options).

## A starting price: "dès 130 € HT"

```php
$product->getPriceRange();      // [lowest, highest] of its variants still for sale; its own price without variants
$product->setPriceFrom(30000);  // typed by hand, for what is priced on request: shown, never charged
$product->getStartingPrice();   // priceFrom, else the lowest of the range
$product->isPricedFrom();       // typed, or the lowest of several
```

```twig
{% include '@Marketplace/client/_price_from.html.twig' with {product: product, price_tax: 'excl'} %}   {# dès 130,00 € HT #}
{% include '@Marketplace/client/_price.html.twig' with {amount: 13000, currency: 'EUR', price_from: true, price_tax: 'incl'} %}
```

The product card shows the starting price when there is one.

## Files: with a quote request, for an order line

`Entity\Attachment` (table `marketplace_attachment`) is a file somebody gave
the shop, kept **outside the public directory** under a random name:

```yaml
marketplace:
    attachments:
        directory: '%kernel.project_dir%/var/storage/marketplace'
        max_size: 20971520      # bytes
        max_files: 5            # per request, per line
        extensions: [pdf, png, jpg, jpeg, webp, gif, svg, ai, eps, psd, tif, tiff, zip]
```

- **A quote request** takes files ([Quotes](quotes.md)); they are listed on
  the quote in the back office and in the mail to the shop.
- **An order line**: `POST /panier/{order}/ligne/{item}/fichiers`
  (`marketplace_cart_files`, the buyer's own cart, field `files[]`), with
  `@Marketplace/client/_line_files.html.twig` to include where a line needs
  it; or `$attachments->attachToItem($item, $files)` from the application.
  The order in the back office lists them.

Reading one: `marketplace_attachment` (`/marketplace/piece-jointe/{id}`) for
whoever may see the shop's screens (`MARKETPLACE_VIEW`), or through
`$attachments->url($attachment, $ttl)`, a signed link for somebody without an
account - a supplier fetching an artwork. Always a download
(`Content-Disposition: attachment`, `nosniff`): an SVG is never shown in the
site's origin. Keep the directory on a persistent volume, and in the backups.

## Upgrading an application

New tables `marketplace_pickup`, `marketplace_product_option_group`,
`marketplace_product_option`, `marketplace_attachment`; new columns
`priceFrom` on the product, `options` on the order item, `phone` on
`marketplace_quote`; `gatewayName` of the shipping method becomes nullable:

```
bin/console make:migration
bin/console doctrine:migrations:migrate
```

Nothing changes for a shop that uses none of it: `Cart::add()` takes its
options as an optional third argument, `getPriceRange()` still answers
`[price, price]` for a product without variants, the quote form keeps its
address (`marketplace.quotes.path: cotation`).

The stylesheet's custom properties are now defaults of no specificity
(`:where(:root)`): a site's `:root { --marketplace-accent: … }` wins whatever
the order of the stylesheets. They were set on `.marketplace`, which beat
`:root`; a value set on `.marketplace` or `.marketplace-store-<slug>` still wins.
