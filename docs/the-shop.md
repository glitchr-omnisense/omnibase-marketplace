---
title: "The shop"
order: 20
---

# The shop

What every shop on omnibase/marketplace has, whatever its trade: stores,
products, carts, orders, VAT, promotions, shipping, payments and the quick
order. The trades' additions have pages of their own.

## Products and stores

A product travels by post unless its class says otherwise:
`Product::isShippable()` returns true. An application sells its own things
by subclassing `Product` with a `#[DiscriminatorEntry]` value of its own; it
overrides `getMaxQuantity()` to return 1 for what is owned once (an avatar
item, a licence). A store's currency wins over `marketplace.default_currency`.

## Promotions and coupons

`Base\Marketplace\Service\Pricing` prices every cart when it is shown and
again at checkout, and never reprices a paid order. It works from the
discounts the back office defines:

- **Promotions** run by themselves between `validAt` and `expiredAt`, highest
  `priority` first: an event, a sale.
- **Coupons** are codes the member types in the cart. `quota` and
  `quotaPerCustomer` cap how many paid orders may use one, `owner` reserves it
  to one member. A coupon marked `individualUse` stands alone, with no
  promotion and no other coupon.

Each discount holds three kinds of attributes:

| Kind | What it does | Adapters |
|---|---|---|
| Rules | all must hold for the order | total price, cart contains, cart quantity |
| Scopes | choose the lines a per-line action touches; with none, every line | store, product, taxon, user, region, order |
| Actions | take something off | percentage, fixed amount |

An action set to apply to items cuts each line in scope; otherwise it cuts the
order. Nothing is ever cut below zero.

## Shipping

Checkout asks for a delivery address and a shipping method only when some
line is shippable. A shipping method in the order's currency is offered with
its charge: `RATE_FLAT` costs its unit price once, `RATE_PRIORITY` costs it
per shipping unit (from the product's weight, or one per item).

The staff see the paid orders waiting at `/admin/commandes/a-expedier`
(`marketplace_shipping_queue`, `ROLE_ADMIN`); its former address,
`/commandes/a-expedier`, redirects there. They mark one shipped with its
tracking number, which creates a `Shipment`, and later delivered. The member
follows the parcel on their order page; a tracking URL may hold `{number}`.

The carrier is omnisense/omnibus's: a shipping method names a gateway
configured under `omnibus.gateways` (`gatewayName`), and when that package is
installed `Service\Shipping` books the parcel there (`book()`: the label, the
tracking number on the shipment) and follows it (`track()`). What the buyer
pays stays the method's own price; `ratesFor()` says what the carrier's
tariff would be. The sender is `marketplace.shipping.sender`.

## Payments

A payment method is a row: a slug, a label, and its gateway's `name()` as
`gatewayFactory`. Real money goes through omnisense/omnitrade: a method whose
gateway names an `omnitrade.gateways` entry is served by the bridge
`Payment\Omnitrade\OmnitradeGateway`, found by `PaymentGatewayRegistry` after
the application's own gateways. The bridge describes the order as an
omnitrade `Payment` (its lines when they add up to the net price, else one
line; the buyer; where to come back), takes the provider's answer as a
`PaymentResult` (paid, a page to send the buyer to, pending, refused) and
keeps the provider's reference on the `Transaction`.
`marketplace.gateways.<slug>` holds what is the method's own (`currencies`,
`instructions`, `method`).

The buyer comes back to `marketplace_payment_return`, which asks the provider
where the payment stands; the provider's webhook posts to
`marketplace_payment_webhook`. Both confirm the same transaction, once
(`Checkout::confirm()` is idempotent). Refunds go back the same way
(`OmnitradeGateway::refund()`).

For Stripe, `marketplace:stripe:webhook` creates the webhook endpoint
(`https://<host>/marketplace/<gateway>/webhook`, the omnitrade gateway's name, with the Checkout events) if
Stripe lacks it, completes its events otherwise; `--dry-run` says what it
would do, `--recreate` makes a new endpoint (hence a new signing secret),
`--url` gives the address. Stripe gives the signing secret only when the
endpoint is created: the command prints it, for `STRIPE_WEBHOOK_SECRET`.

An application takes payment its own way with a service implementing the
gateway interface, picked up by autoconfiguration:

```php
final class PepettesGateway implements PaymentGatewayInterface
{
    public static function name(): string { return 'pepettes'; }
    public function supports(Order $order, PaymentMethod $method): bool { return 'PEP' === $order->getCurrency(); }
    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        // debit, then:
        return PaymentResult::paid(); // or pending(), redirect($url), refused('reason.key', [...])
    }
}
```

A refusal reason is a translation key in the `marketplace` domain.

## After a sale

`Base\Marketplace\Event\OrderPaidEvent` is dispatched once the order is
confirmed and its stock decremented: where an application delivers virtual
goods, credits an account, or tells the staff to ship. The other events are
listed in the [reference](reference.md#events).

## The quick order

A product bought in one step, with no account and no basket: an e-mail
address, the payment.

```twig
{% include '@Marketplace/client/_quick_order.html.twig' with {product: product} %}
```

`Service\QuickOrder::buy()` makes the order for that address (the member it
belongs to, or a new one whose password nobody knows), `Checkout::settle()`
takes the payment with the shop's first able method, and the buyer ends on
the order's own page: a signed link (`QuickOrder::doneUrl()`, valid
`marketplace.quick_order.link_ttl` seconds) that is also good to mail.
`Event\QuickOrderDoneEvent` lets an application put something on that page
(files to download, a pickup time) by setting a response.

The form is `Form\QuickOrderType` (`marketplace_quick_order_form(product,
back)` gives it to a template), guarded as omnisense/omnibase guards a form
(action `quick_order`: a trap, the time it takes, the lists, the captcha when
the site has omnisense/omnishield); a signed-in member is asked no address. A
buyer back from the payment provider in the same browser lands on that page
too (`EventListener\QuickOrderReturnListener`).
