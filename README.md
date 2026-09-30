# base-bundle-marketplace

A shop for [glitchr/base-bundle](https://gitlab.glitchr.dev/public-repository/symfony/bundle/base) 3.x applications: stores, products and variants, carts, checkout, orders, and payment through pluggable gateways. It is the marketplace of a shop site, ported to base-bundle 3.x attributes and stripped of that shop's wallpapers. What a shop sells is the application's business: products are subclassed in the app.

Namespace `Base\Marketplace`, package `glitchr/base-bundle-marketplace`.

## What it gives you

- **Stores.** A store has a title, a slug, a currency and an open or closed flag. Each store keeps its own cart per member.
- **Products.** Products belong to a store and carry a price in the currency's smallest unit. Stock is optional, and an empty stock means unlimited. Subclass `Product` for what your shop really sells.
- **Carts and checkout.** A member gets one cart order per store. Checkout creates a transaction, hands it to a payment gateway, then confirms or cancels the order.
- **Orders.** Each order gets a readable reference such as `CCC-XXXX-YYY`, a state, a paid date and its transactions.
- **Payment gateways.** A gateway is any service implementing `PaymentGatewayInterface`. The bundle ships `stripe` (card payment through Stripe Checkout, the default) and `manual`, which records a pending payment and shows the method's instructions.
- **Admin CRUDs.** Store, Product, Order and PaymentMethod get CRUDs under `Controller/Admin/Crud`, loaded only when base-bundle-admin is installed.
- **Pages.** Client pages are in French by default:

| Route | Path |
|---|---|
| `marketplace_stores` | `/boutiques` |
| `marketplace_store` | `/boutique/{slug}` |
| `marketplace_product` | `/boutique/{store}/{slug}` |
| `marketplace_cart` | `/panier` |
| `marketplace_checkout` | `/panier/{order}/commander` |
| `marketplace_orders` | `/commandes` |
| `marketplace_order` | `/commandes/{reference}` |
| `marketplace_stripe_return` | `/panier/{order}/stripe` |
| `marketplace_stripe_webhook` | `/marketplace/stripe/webhook` (POST) |

The promotions, fees, taxes, shipping and review entities come from a shop site and are mapped. The shop pages do not use them yet.

## Try it in one command

A self-contained demo ships in the root [Dockerfile](Dockerfile): a bare Symfony skeleton, base-bundle, this checkout and SQLite. Its first page does four things. It seeds a store in euros with three products and two payment methods, and signs you in. It fills a cart, pays one order through the application's own gateway and one by bank transfer. Then it links to the shop itself:

```bash
docker build -t base-bundle-marketplace-demo .
docker run --rm -p 8000:8000 base-bundle-marketplace-demo
# → http://localhost:8000/                   the tour
# → http://localhost:8000/boutique/boutique  the shop
```

The demo app under [example/app/](example/app/) doubles as the minimal host. It holds the `bundles.php`, `routes.yaml`, `doctrine.yaml`, `security.yaml` and `marketplace.yaml` an application needs. It adds a gateway of its own in `src/Payment/DemoGateway.php`, and a User with a username. Its `layout1.html.twig` shows the only contract the shop's templates have with their host: the `content`, `aside`, `title`, `stylesheets` and `javascripts` blocks.

## Install

```bash
composer require glitchr/base-bundle-marketplace:dev-main
```

Register the bundle in `config/bundles.php`:

```php
Base\Marketplace\MarketplaceBundle::class => ['all' => true],
```

Import the client routes. `MarketplaceBundle::getPath()` is the package root, so the path includes `src/`:

```yaml
# config/routes.yaml
marketplace_controller:
    resource: "@MarketplaceBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

Bind the seller to a class of yours. Products, stores and orders point at `Base\Marketplace\Model\MerchantInterface`, which only asks for `getCompanyName()`:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        resolve_target_entities:
            Base\Marketplace\Model\MerchantInterface: App\Entity\User
```

Then create the tables with a migration, and publish the stylesheet:

```bash
bin/console assets:install
```

The pages extend `layout1.html.twig` and fill its `content`, `aside`, `title` and `stylesheets` blocks.

## Configure

```yaml
# config/packages/marketplace.yaml
marketplace:
    default_currency: EUR     # a store's currency wins over this
    products_per_page: 24
    orders_per_page: 20
    cart_max_quantity: 99     # per line; Product::getMaxQuantity() can lower it
    guest_cart: false
    gateways:                 # settings per payment method, keyed by its slug
        virement:
            currencies: [EUR] # keeps the method off orders in other currencies
            instructions: "IBAN FR76 ..., reference of the order in the label."
```

A payment method is a row: a slug, a label, and its gateway's `name()` as `gatewayFactory`. Create it in the admin or in fixtures.

## Promotions and coupons

`Base\Marketplace\Service\Pricing` prices every cart when it is shown and again at checkout, and never reprices a paid order. It works from the discounts the admin defines:

- **Promotions** run by themselves between `validAt` and `expiredAt`, highest `priority` first. Use them for an event or a sale.
- **Coupons** are codes the member types in the cart. `quota` and `quotaPerCustomer` cap how many paid orders may use one, and `owner` reserves it to one member. A coupon marked `individualUse` stands alone, with no promotion and no other coupon.

Each discount holds three kinds of attributes, edited in the admin:

| Kind | What it does | Adapters |
|---|---|---|
| Rules | All must hold for the order. | Total price, cart contains, cart quantity |
| Scopes | Choose the lines a per-line action touches. With none, it touches every line. | Store, product, taxon, user, region, order |
| Actions | Take something off. | Percentage, fixed amount |

An action set to apply to items cuts each line in scope. Otherwise it cuts the order. Nothing is ever cut below zero.

## Shipping

A product travels by post unless its class says otherwise: `Product::isShippable()` returns true. Subclass it to return false for goods that live online. Checkout asks for a delivery address and a shipping method only when some line is shippable.

A shipping method in the order's currency is offered with its charge. `RATE_FLAT` costs its unit price once. `RATE_PRIORITY` costs it per shipping unit, where a unit comes from the product's weight, or one per item.

Staff see the paid orders waiting at `/commandes/a-expedier` (`marketplace_shipping_queue`, `ROLE_ADMIN`). They mark one shipped with its tracking number, which creates a Shipment, and later delivered. The member follows the parcel on their order page, and a tracking URL may hold `{number}`.

## Card payment with Stripe

Stripe is the default way to pay real money. The bundle ships a `stripe` gateway on omnipay/stripe's Checkout gateway, like a shop site. The member pays on Stripe's hosted page and comes back to `marketplace_stripe_return`. The webhook `marketplace_stripe_webhook` confirms the order even if they never come back.

```bash
composer require omnipay/stripe
```

```yaml
# config/packages/marketplace.yaml
marketplace:
    default_gateway: stripe          # offered first at checkout
    gateways:
        stripe:                      # the payment method's slug
            api_key: '%env(default::STRIPE_API_KEY)%'
            webhook_secret: '%env(default::STRIPE_WEBHOOK_SECRET)%'   # optional, see below
            webhook_url: '%env(default::STRIPE_WEBHOOK_URL)%'         # for marketplace:stripe:webhook
            currencies: [EUR]
```

Create a payment method with the slug `stripe` and the gateway `stripe`. While `api_key` is empty, checkout doesn't offer it.

The webhook endpoint - `https://<host>/marketplace/stripe/webhook` with the `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `checkout.session.expired` events - is created by a command, safe to run on every deploy:

```bash
bin/console marketplace:stripe:webhook              # creates it if Stripe lacks it, completes its events otherwise
bin/console marketplace:stripe:webhook --dry-run    # says what it would do
bin/console marketplace:stripe:webhook --recreate   # a new endpoint, hence a new signing secret
```

The address is `--url`, else `webhook_url`, else the route under the router's default URI. With no API key, or an address Stripe cannot reach (localhost - use `stripe listen` there), it does nothing and succeeds. Stripe returns an endpoint's signing secret only when it is created: the command stores it in the settings (`marketplace.stripe.<slug>.webhook_secret`, secured), and the gateway reads it there when `webhook_secret` is empty - nothing to copy. Every event's signature is checked against that secret; a `webhook_secret` that is set wins, which is how `stripe listen`'s secret is used locally. An endpoint made by hand in the dashboard is found by its URL; its secret has to be revealed there into `webhook_secret`, or replaced with `--recreate`.

A cancelled or expired payment puts the order back in the cart. A gateway error does the same, and the member sees why.

## Shopify (optional)

An existing Shopify shop can be plugged in as a catalogue, as a checkout, as a fulfilment desk, or as all three. It is **off unless you ask for it**: `src/Shopify/` is excluded from the container, its entity is not mapped, and no dependency is added — an application that does not set `marketplace.shopify` gets exactly the bundle it had before.

```bash
composer require symfony/http-client   # required; symfony/messenger for the order push
```

```yaml
# config/packages/marketplace.yaml
marketplace:
    shopify:
        enabled: true                                   # a literal true — see the note below
        shop_domain: '%env(default::SHOPIFY_SHOP_DOMAIN)%'
        api_version: '2026-07'
        admin_token: '%env(default::SHOPIFY_ADMIN_TOKEN)%'
        webhook_secret: '%env(default::SHOPIFY_WEBHOOK_SECRET)%'

        catalogue:                      # role 1: products and stock come from Shopify
            enabled: true
            store: boutique             # the Store slug they attach to
        checkout:                       # role 2: the member pays on Shopify
            enabled: true
        export:                         # role 3: paid orders go to Shopify to be shipped
            enabled: false
```

`enabled` must be a literal boolean, never an env placeholder. The extension branches on it while compiling the container, where `%env(...)%` is still an unresolved string — so an env var there would read as "on" for every host that merely declared it. Secrets stay env vars as usual; an unset one resolves to an empty string and every consumer treats that as "not configured" and declines quietly.

In the Shopify admin, create a **custom app** (Settings → Apps → Develop apps) with the narrowest scopes for the roles you turned on — `read_products` and `read_inventory` for the catalogue, `write_draft_orders` and `read_orders` for the checkout, `write_orders` for the push. Do not grant `write_products`: the sync is one way. Copy the Admin API access token and the app's API secret key into your env.

```bash
bin/console marketplace:shopify:ping                              # domain, token and version, in one call
bin/console marketplace:shopify:catalogue:sync --dry-run --limit=5 # read-only: prints what would change
bin/console marketplace:shopify:catalogue:sync                     # then for real
bin/console marketplace:shopify:webhooks --install                 # subscribe to the seven topics
bin/console marketplace:shopify:orders:reconcile                   # cron, every 10 minutes
```

**The catalogue** is read one way, Shopify → shop, one row per variant. Only the fields under `catalogue.owned_fields` are ever written, so taxa, channels, owners and anything your own `Product` subclass adds survive untouched. A product whose owned fields have not moved is skipped without a write, which keeps `updatedAt` — and every cache keyed on it — still. Tag a product `shopify-unmanaged` in the admin to pin it and have the sync leave it alone entirely. Nothing is ever deleted: a product that goes away in Shopify is marked `DISCONTINUED`, because `Product` is the inverse side of `OrderItem` and deleting one would tear a hole in order history.

**The checkout** creates a Shopify draft order and sends the member to its invoice page. Draft orders rather than a Storefront cart because their line items take *your* prices: `Pricing` has just applied promotions, coupons, fees, shipping and VAT, and a Storefront cart would throw all that away and recompute. It also means the checkout works before any catalogue sync — a custom line item needs no variant id.

Note there is **no return leg**: a Shopify invoice checkout ends on Shopify's own thank-you page and never comes back. The `orders/paid` webhook is the real confirmation; `marketplace_shopify_check` ("I have paid — check now") lets an impatient member poll from their pending order, and `marketplace:shopify:orders:reconcile` sweeps up anything a lost webhook left behind. That last one is also what makes the whole thing usable with no webhooks at all, which is what local development needs.

**The order push** listens to `OrderPaidEvent` and goes through Messenger rather than calling Shopify inline — `Checkout::confirm()` runs inside somebody else's webhook, and a failed synchronous call there would be retried by the payment provider, hit `confirm()`'s idempotency guard, and be lost silently. Route it and run a worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Base\Marketplace\Shopify\Export\PushOrderMessage: async
```

Orders Shopify created itself are never pushed back, and neither are orders made entirely of things that do not ship (`export.only_shippable`). Writing customer emails and addresses needs Shopify's **protected customer data** approval — a review with a lead time, so apply for it before you need it.

**Webhooks** are verified with `X-Shopify-Hmac-Sha256` (base64 of the raw digest, unlike Stripe's hex, and with no timestamp — so there is no freshness window and replay defence is the delivery id plus the fact that every action is idempotent). The shop domain is checked too. An unrecognised topic answers 200: Shopify deletes a subscription after eight hours of continuous failure.

## Business customers: VAT numbers and company numbers

The marketplace checks public registers through [Omnistate](https://gitlab.glitchr.dev/public-repository/agnostic/omnistate/omnistate). Register its bundle:

```php
// config/bundles.php
Omnistate\Bridge\Symfony\OmnistateBundle::class => ['all' => true],
```

```yaml
# config/packages/omnistate.yaml
omnistate:
    requester: FR53901821074   # your VAT number: VIES then answers a consultation number, the proof of each check
```

**Reverse charge.** Implement `Base\Marketplace\Model\VatCustomerInterface` on your User, usually with `VatCustomerTrait` (three columns: the number, when VIES confirmed it, its consultation number). `Service\VatNumbers::confirm($user, $typed)` checks a number with VIES and keeps it when VIES knows it. It accepts a SIREN or a SIRET for a French business. It flushes nothing and returns a status (`confirmed`, `removed`, `not_a_number`, `unknown`, `unavailable`). `VatNumbers::check($typed)` checks any number, a store's for instance.

A customer with a confirmed number from another EU country than the store's is then sold without VAT. `Pricing\ReverseCharge` puts the mention on the order: the directive's article, the customer's number and VIES's consultation number. A store's country is its VAT number's prefix. It runs after `StoreVatRegime`, so a store that charges no VAT says so first.

**Company numbers.** With `omnistate/annuaire-entreprises` installed, `Service\CompanyRegistry` looks up a French SIREN or SIRET in the State's free register. The `#[CompanyNumber]` constraint refuses a number the register doesn't know, and lets it through when the register doesn't answer. `GET /api/company/{number}` (route `marketplace_company_lookup`) answers the company as JSON, for a form that fills the name as the number is typed.

## Extend

**Sell your own things.** Subclass `Product` in your app, with a `#[DiscriminatorEntry]` value of its own. Override `getMaxQuantity()` to return 1 for things that are owned once, such as an avatar item or a licence.

**Take payment your way.** Implement the gateway interface. The service is picked up by autoconfiguration:

```php
final class TokensGateway implements PaymentGatewayInterface
{
    public static function name(): string { return 'tokens'; }
    public function supports(Order $order, PaymentMethod $method): bool { return 'PEP' === $order->getCurrency(); }
    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        // debit, then:
        return PaymentResult::paid(); // or pending(), redirect($url), refused('reason.key', [...])
    }
}
```

A refusal reason is a translation key in the `marketplace` domain.

**React to a sale.** Listen for `Base\Marketplace\Event\OrderPaidEvent`. It is dispatched once the order is confirmed and its stock decremented. That is where an app delivers virtual goods, or tells the staff to ship.

**Restyle.** Every class is `.marketplace-*` and driven by custom properties on `.marketplace`. A store's page also carries `.marketplace-store-<slug>`. Override any template under `templates/bundles/MarketplaceBundle/client/`. `_price`, `_product_media` and `_banner` exist for exactly that. Call the original with `@!Marketplace/client/…`.

## Requirements

PHP 8.2+, Symfony 7.4 or 8, Doctrine ORM 3, glitchr/base-bundle 3.x and glitchr/base-bundle-admin.

## Licence

See the repository.
