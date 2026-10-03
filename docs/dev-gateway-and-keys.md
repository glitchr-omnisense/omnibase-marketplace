# The "dev" gateway and the shop's keys

## Paying in development: the `dev` gateway

`Base\Marketplace\Payment\DevGateway` pays an order at once, by nobody, so a
checkout can be walked through without a Stripe key. It is registered only when
the kernel runs in debug (`MarketplaceExtension`), and its `supports()` refuses
outside debug all the same: it can never show in production.

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

## Stripe's keys on the API keys page

With omnibase/admin installed and glitchr/omnitrade's `stripe` gateway
registered, `Base\Marketplace\Settings\PaymentKeysSection` puts Stripe's three
keys on `/admin/api-key`: `api.payment_method.stripe.publishable`,
`api.payment_method.stripe.api_key`, `api.payment_method.stripe.webhook_secret`.
Typed there, they win over the configuration (`OmnitradeGateways`). See
omnibase/admin's `docs/system-pages.md` for the sections.
