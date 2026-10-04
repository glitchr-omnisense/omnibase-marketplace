---
title: Lists of wishes
order: 70
---

# Lists of wishes, reservations, contributions

A wedding's or a birthday's gifts, a birth list, a fund: a list
(`Entity\Wishlist\Wishlist`) of wishes (`Item`) that are objects from **any
shop**, products of this shop, or pots. Those who give either **reserve** an
object - so nothing is given twice - and buy it themselves, or
**contribute** money, paid straight to the list's owner (Stripe Connect):
the platform never holds a gift.

## Installation

The update adds five tables (`marketplace_wishlist`,
`marketplace_wishlist_item`, `marketplace_wishlist_reservation`,
`marketplace_wishlist_contribution`, `marketplace_payout_account`): a
migration (`doctrine:migrations:diff`, `migrate`).

Reading a pasted address needs `glitchr/omnitrade` and a gateway that reads
product pages (`omnitrade/web`, `omnitrade/amazon`); contributions need its
Connect and `omnitrade/stripe`. Without them lists and reservations work,
wishes are written by hand.

```yaml
# config/packages/marketplace.yaml
marketplace:
    wishlist:
        lookup: [amazon, web]      # the omnitrade gateways asked, in order, to read an address and write its affiliate link
        payout_gateway: stripe     # whose connected accounts receive the contributions
        fee_rate: 0.03             # the platform's fee on a contribution...
        fee_fixed: 0               # ...plus this, in minor units
        minimum: 500               # the smallest contribution, in minor units
        reservation_days: 30       # a promise not confirmed lapses
        price_ttl: 86400           # seconds before a price read from a shop is read again
```

```
# crontab: every night
30 3 * * *  php bin/console marketplace:wishlist:refresh-prices
```

The bundle brings no page: a list's look is the application's. It brings the
model, the services and the webhook's handling.

## A list and its wishes

```php
public function __construct(private Wishlists $wishlists) {}

$list = $wishlists->create($user, 'Liste de naissance', WishlistKind::BIRTH, $occasion);   // $occasion: a WishlistHolderInterface, or null
$wishlists->addFromUrl($list, 'https://boutique.example/p/poussette');     // read from its page: title, picture, price, merchant, affiliate link
$wishlists->addProduct($list, $product);                                   // one of this shop's products
$wishlists->addFund($list, 'Voyage de noces');                             // a pot
$wishlists->addFund($list, 'Robot pâtissier', 59900, WishlistItemKind::SHARE);   // an object several pay for together
```

| `Item` kind | How it is given |
|---|---|
| `OBJECT` | reserved (`quantity` in all), bought by the giver at `getBuyUrl()` - the affiliate link when the shop has a programme |
| `SHARE` | contributions, up to its price times its quantity (`getRemaining()`) |
| `FUND` | contributions without ceiling; its price, when set, is a goal to show |

A list belongs to its `owner` and, when the application says so, to a
*holder* of its own (`Model\WishlistHolderInterface`: a type and an id, no
relation); `WishlistRepository::findForHolder($occasion)` finds its lists.
Its `token` is its public address' part. `surprise` (a birth list's default)
tells the application to show its owner that a wish is taken, not by whom.

A price read from a shop is the page's at that time: show it with
`getPriceFetchedAt()`. `marketplace:wishlist:refresh-prices` reads again
those older than `price_ttl`.

## Reservations

```php
['reservation' => $reservation, 'token' => $token] = $reservations->reserve($item, 1, 'Tante Odile', 'odile@example.org');
// send $token to its author: $reservations->cancel($token) puts the object back
$reservations->confirm($reservation);   // bought: it no longer lapses
```

The item's row is locked while what is left is counted, in the database:
two givers cannot both take the last one (`WishlistException`
`wishlist.error.taken`, its `{left}`). Only the token's hash is kept. A
promise not confirmed lapses after `reservation_days`.

## Contributions

1. **The owner's account**, once: `PayoutAccounts::open($user, 'FR')` opens
   an Express account, `onboardingUrl()` gives the provider's page where
   they give their identity and bank account, `refresh()` (on their return)
   or the `account.updated` webhook says when it `isReady()`. Put it on the
   list: `$list->setPayoutAccount($account)`.
2. **A gift**:

```php
$contribution = $contributions->start($item, 5000, 'Tom', $returnUrl, $cancelUrl, [
    'email' => 'tom@example.org', 'message' => 'Bon voyage !', 'coverFees' => true, 'giverReference' => (string) $household->getId(),
]);
return new RedirectResponse($contribution->getRedirectUrl());

// on $returnUrl:
$contribution = $contributions->confirm($contributions->find($reference));
```

The payment is a **destination charge**: taken on the platform's Checkout
page, transferred to the owner's account, the platform keeping its fee
(`fee_rate` of the gift plus `fee_fixed`). With `coverFees` the giver pays
the gift plus the fee and the owner receives the gift whole
(`getCharged()`, `getReceived()`).

| Event | When | For |
|---|---|---|
| `ContributionPreparingEvent` | before the payment | set `$fee` (a rate from the owner's plan), add `$metadata`, the `$description` of the provider's page |
| `ContributionPaidEvent` | paid, once - whichever of the return page and the webhook says it first | thank, note the gift, tell the owner |

The provider's webhooks reach `/marketplace/{gateway}/webhook` (forward the
Connect events too); `EventListener\WishlistNotificationListener` applies a
contribution's payment and a payout account's update.

A contribution is not an order of the shop: it is not the platform's
revenue, has no VAT, takes no coupon. The platform's fee is what its
provider's reports show as application fees.

## Errors

`Wishlist\WishlistException`'s message is a key of the `marketplace`
translations, with its parameters: `wishlist.error.url`, `.closed`,
`.not_reservable`, `.not_fund`, `.taken`, `.share_price`, `.no_payout`,
`.minimum`, `.too_much`, `.payment`.
