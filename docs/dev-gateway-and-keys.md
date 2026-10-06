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

### Lists, payout accounts, the catalogue: the same rule, at the one door

The checkout is not the only thing that talks to glitchr/omnitrade. A list's
contributions (`Wishlist\Contributions`: a destination charge to its owner's
connected account), that account itself (`Wishlist\PayoutAccounts`: opened,
completed and refreshed at the provider), an address read for a wish
(`Wishlist\ProductLookup`) all ask `Payment\Omnitrade\OmnitradeGateways`
directly, and the catalogue read from a platform asks omnitrade's registry
(`Catalogue\PlatformSynchronizer`). A demonstration holding real keys would
have charged a card, opened an Express account and read a shop.

In `demo`, **no path of the bundle reaches a gateway of `omnitrade.gateways`**:

| | In `demo` |
|---|---|
| `OmnitradeGateways::get('stripe')` (any configured name) | `null`, whatever keys are typed or configured; the first time each is asked, a line in the log (`Demonstration: the omnitrade gateway "stripe" was asked for and refused`) |
| `OmnitradeGateways::names()` | the application's trial gateways only (none by default) |
| `Contributions::start()` | `WishlistException('wishlist.error.payment')`, nothing sent, nothing kept as paid |
| `PayoutAccounts::isAvailable()` | `false`; `open()`, `refresh()`, `onboardingUrl()` throw `WishlistException('wishlist.error.no_payout')` |
| `ProductLookup::lookup()`, `::affiliateLink()` | `null`: a pasted address is kept as it is |
| `PlatformSynchronizer::gateway()` (`catalogue:sync`, the stock) | `LogicException`: the demonstration's catalogue is its fixtures' |

**A trial gateway of the application's own** is the one way through - for a
site whose lists must take contributions in its demonstration. It writes a
glitchr/omnitrade factory whose gateways move no money and marks it
`Base\Marketplace\Payment\Omnitrade\TrialGatewayFactoryInterface`:

```php
#[When(env: 'demo')]
final class TrialGatewayFactory implements TrialGatewayFactoryInterface
{
    public function getName(): string { return 'essai'; }

    public function create(array $options = []): GatewayInterface
    {
        // accounts ready at once, a contribution paid on the spot, by nobody
        return new Gateway('essai', 'Paiement d’essai (aucun débit)', [new TrialAction()]);
    }
}
```

```yaml
# config/packages/marketplace.yaml
when@demo:
    marketplace:
        wishlist:
            payout_gateway: essai      # the trial factory's name
```

In `demo`, `OmnitradeGateways::get('essai')` gives the gateway that factory
makes - built from the factory itself, never through `omnitrade.gateways` -
and nothing else answers. The interface is a promise the marketplace cannot
check: what such a gateway does is the application's. Outside `demo` it
changes nothing: there the gateways are the configured ones, and a trial
factory is not asked. A factory that is only listed under
`omnitrade.gateways` without the interface is refused in `demo` like any
other.

## Stripe's keys on the API keys page

With omnibase/admin installed and glitchr/omnitrade's `stripe` gateway
registered, `Base\Marketplace\Settings\PaymentKeysSection` puts Stripe's three
keys on `/admin/api-key`: `api.payment_method.stripe.publishable`,
`api.payment_method.stripe.api_key`, `api.payment_method.stripe.webhook_secret`.
Typed there, they win over the configuration (`OmnitradeGateways`). See
omnibase/admin's `docs/system-pages.md` for the sections.
