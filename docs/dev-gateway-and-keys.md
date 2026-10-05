# The "dev" gateway and the shop's keys

## Paying in development: the `dev` gateway

`Base\Marketplace\Payment\DevGateway` pays an order at once, by nobody, so a
checkout can be walked through without a Stripe key. It is registered only when
the kernel runs in debug, or in the `demo` environment (`MarketplaceExtension`),
and its `supports()` refuses anywhere else all the same: it can never show in
production.

To offer it, give a payment method the gateway factory `dev` (in the back
office, or in the fixtures):

```php
$method = new PaymentMethod();
$method->setSlug('dev');
$method->setLabel('Essai (développement)');
$method->setGatewayFactory('dev');
```

The applications delete their `App\Market\DevGateway`: the registry keys
gateways by name, and this one is `dev`.

## Paying in a demonstration: the `dev` gateway, and no other

glitchr/omnibase's `demo` environment (its `docs/20-architecture/demo.md`)
runs a site as production does, without debug - and no money may move there.
In `demo`, and there only besides debug:

- the `dev` gateway is registered and takes the orders: the fixtures' "dev"
  payment method is the one a visitor pays with;
- **the gateways of glitchr/omnitrade are not there at all**
  (`PaymentGatewayRegistry` leaves them out): a method on `stripe`, `paypal`
  or any gateway of `omnitrade.gateways` is not offered at the checkout, its
  return page and its webhook find no gateway, and the back office does not
  list them for a payment method. The keys typed on the API keys page change
  nothing to that.

The application's own gateways (the `manual` one - a transfer, a payment at
the counter - and whatever it tagged `marketplace.payment_gateway`) stay: a
gateway of its own that reaches a real provider says no in `demo` itself
(`supports()`).

## Stripe's keys on the API keys page

With omnibase/admin installed and glitchr/omnitrade's `stripe` gateway
registered, `Base\Marketplace\Settings\PaymentKeysSection` puts Stripe's three
keys on `/admin/api-key`: `api.payment_method.stripe.publishable`,
`api.payment_method.stripe.api_key`, `api.payment_method.stripe.webhook_secret`.
Typed there, they win over the configuration (`OmnitradeGateways`). See
omnibase/admin's `docs/system-pages.md` for the sections.
