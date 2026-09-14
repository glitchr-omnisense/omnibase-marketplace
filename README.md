# base-bundle-market

A shop for [glitchr/base-bundle](https://gitlab.glitchr.dev/public-repository/symfony/bundle/base) 3.x applications: stores, products and variants, carts, checkout, orders, and payment through pluggable gateways. It is the marketplace of a shop site, ported to base-bundle 3.x attributes and stripped of that shop's wallpapers. What a shop sells is the application's business: products are subclassed in the app.

Namespace `Base\Market`, package `glitchr/base-bundle-market`.

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
| `market_stores` | `/boutiques` |
| `market_store` | `/boutique/{slug}` |
| `market_product` | `/boutique/{store}/{slug}` |
| `market_cart` | `/panier` |
| `market_checkout` | `/panier/{order}/commander` |
| `market_orders` | `/commandes` |
| `market_order` | `/commandes/{reference}` |
| `market_stripe_return` | `/panier/{order}/stripe` |
| `market_stripe_webhook` | `/market/stripe/webhook` (POST) |

The promotions, fees, taxes, shipping and review entities come from a shop site and are mapped. The shop pages do not use them yet.

## Try it in one command

A self-contained demo ships in the root [Dockerfile](Dockerfile): a bare Symfony skeleton, base-bundle, this checkout and SQLite. Its first page does four things. It seeds a store in euros with three products and two payment methods, and signs you in. It fills a cart, pays one order through the application's own gateway and one by bank transfer. Then it links to the shop itself:

```bash
docker build -t base-bundle-market-demo .
docker run --rm -p 8000:8000 base-bundle-market-demo
# → http://localhost:8000/                   the tour
# → http://localhost:8000/boutique/boutique  the shop
```

The demo app under [example/app/](example/app/) doubles as the minimal host. It holds the `bundles.php`, `routes.yaml`, `doctrine.yaml`, `security.yaml` and `market.yaml` an application needs. It adds a gateway of its own in `src/Payment/DemoGateway.php`, and a User with a username. Its `layout1.html.twig` shows the only contract the shop's templates have with their host: the `content`, `aside`, `title`, `stylesheets` and `javascripts` blocks.

## Install

```bash
composer require glitchr/base-bundle-market:dev-main
```

Register the bundle in `config/bundles.php`:

```php
Base\Market\MarketBundle::class => ['all' => true],
```

Import the client routes. `MarketBundle::getPath()` is the package root, so the path includes `src/`:

```yaml
# config/routes.yaml
market_controller:
    resource: "@MarketBundle/src/Controller/Client"
    type: attribute
    prefix: /
```

Bind the seller to a class of yours. Products, stores and orders point at `Base\Market\Model\MerchantInterface`, which only asks for `getCompanyName()`:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        resolve_target_entities:
            Base\Market\Model\MerchantInterface: App\Entity\User
```

Then create the tables with a migration, and publish the stylesheet:

```bash
bin/console assets:install
```

The pages extend `layout1.html.twig` and fill its `content`, `aside`, `title` and `stylesheets` blocks.

## Configure

```yaml
# config/packages/market.yaml
market:
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

`Base\Market\Service\Pricing` prices every cart when it is shown and again at checkout, and never reprices a paid order. It works from the discounts the admin defines:

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

Staff see the paid orders waiting at `/commandes/a-expedier` (`market_shipping_queue`, `ROLE_ADMIN`). They mark one shipped with its tracking number, which creates a Shipment, and later delivered. The member follows the parcel on their order page, and a tracking URL may hold `{number}`.

## Card payment with Stripe

Stripe is the default way to pay real money. The bundle ships a `stripe` gateway on omnipay/stripe's Checkout gateway, like a shop site. The member pays on Stripe's hosted page and comes back to `market_stripe_return`. The webhook `market_stripe_webhook` confirms the order even if they never come back.

```bash
composer require omnipay/stripe
```

```yaml
# config/packages/market.yaml
market:
    default_gateway: stripe          # offered first at checkout
    gateways:
        stripe:                      # the payment method's slug
            api_key: '%env(default::STRIPE_API_KEY)%'
            webhook_secret: '%env(default::STRIPE_WEBHOOK_SECRET)%'
            currencies: [EUR]
```

Create a payment method with the slug `stripe` and the gateway `stripe`. While `api_key` is empty, checkout doesn't offer it. In the Stripe dashboard, point a webhook at `https://<host>/market/stripe/webhook` with the `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `checkout.session.expired` events. Every event's signature is checked against `webhook_secret`.

A cancelled or expired payment puts the order back in the cart. A gateway error does the same, and the member sees why.

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

A refusal reason is a translation key in the `market` domain.

**React to a sale.** Listen for `Base\Market\Event\OrderPaidEvent`. It is dispatched once the order is confirmed and its stock decremented. That is where an app delivers virtual goods, or tells the staff to ship.

**Restyle.** Every class is `.market-*` and driven by custom properties on `.market`. A store's page also carries `.market-store-<slug>`. Override any template under `templates/bundles/MarketBundle/client/`. `_price`, `_product_media` and `_banner` exist for exactly that. Call the original with `@!Market/client/…`.

## Requirements

PHP 8.2+, Symfony 7.4 or 8, Doctrine ORM 3, glitchr/base-bundle 3.x and glitchr/base-bundle-admin.

## Licence

See the repository.
