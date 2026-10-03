---
title: Lots, the age gate, where the shop delivers
order: 21
---

# Lots, the age gate, where the shop delivers

## Selling by the lot

```php
$product->setPackSize(6);          // a case of 6: the cart holds 6, 12, 18...
$product->setMinimumQuantity(12);  // and at least 12 (rounded up to the lot)
$product->boundQuantity(7);        // 12
$product->boundQuantity(40, 30);   // 30 at most: 30
```

A variant without its own takes its principal's. The cart (`Service\Cart`)
rounds what is added up to the lot and the minimum, and down under
`cart_max_quantity`, the product's own maximum and its stock; when not even
the minimum fits, it refuses (`cart.error.minimum`, or `cart.error.sold_out`
for the stock). Adding "one" of a case of 6 adds 6. `Checkout` checks it
again before paying (an order made from a quote is exempt: its quantities are
the quote's). `marketplace.cart_max_quantity` must leave room for a few cases
(120 for a cellar).

## The age gate

Products sold to adults only - `Product::$ageRestricted`, or one of its taxa
(`Product\Taxon::$ageRestricted`, inherited down the tree) - stand behind an
interstitial, once: the answer is kept a year in the `marketplace_age` cookie
(the age confirmed: a yes at 18 does not open a page asking 20).

```yaml
marketplace:
    age_gate:
        enabled: true
        default: 18
        ages: { ja: 20, JP: 20 }    # by language or country; a country wins
        notice: "L'abus d'alcool est dangereux pour la santé, à consommer avec modération."
        site: false                 # true: on every page
```

The visitor's country is read from a `country` cookie or a `CF-IPCountry` /
`X-Country` header when the application sets one. The notice may be a
translation key (`@marketplace.age_gate.notice` is the Évin wording in French,
a Japanese one in Japanese).

```twig
{% if marketplace_age_gate(product) %}{% include '@Marketplace/client/_age_gate.html.twig' with {away: 'https://…'} %}{% endif %}
{% if marketplace_age_gate(true) %}…{% endif %}   {# a page that is restricted as a whole: a cellar's list #}
{{ marketplace_age_minimum() }}  {{ marketplace_age_notice()|trans }}
```

The gate is a `<dialog open>` with a form (`POST /age`, `marketplace_age_gate`):
it works without JavaScript; style `.marketplace-age-gate` or override the
template (its `age_gate_mark` block takes a logo).

## Where the shop delivers

A store's sales regions (`Sales\Region::$countries`) say where its parcels go.
`Shipping::deliversTo($order, 'JP')` is false when the store's enabled regions
list countries and not this one; `Shipping::apply()` then answers
`shipping.error.out_of_zone`, and the checkout sends the buyer to the
quotation form (`marketplace_quote_request`, the country and the cart's
products filled in). A region listing no country goes everywhere. An order
made from a quote goes where its quote says.
