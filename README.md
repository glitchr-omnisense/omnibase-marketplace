# base-bundle-marketplace

A shop for [omnisense/omnibase](https://gitlab.glitchr.dev/public-repository/symfony/bundle/base) 3.x applications: stores, products and variants, carts, checkout, orders, and payment through pluggable gateways. It is the marketplace of latoucheoriginale, ported to base-bundle 3.x attributes and stripped of that shop's wallpapers. What a shop sells is the application's business: products are subclassed in the app.

Namespace `Base\Marketplace`, package `omnibase/marketplace`.

## What it gives you

- **Stores.** A store has a title, a slug, a currency and an open or closed flag. Each store keeps its own cart per member.
- **Products.** Products belong to a store and carry a price in the currency's smallest unit. Stock is optional, and an empty stock means unlimited. Subclass `Product` for what your shop really sells.
- **Carts and checkout.** A member gets one cart order per store. Checkout creates a transaction, hands it to a payment gateway, then confirms or cancels the order.
- **Orders.** Each order gets a readable reference such as `CCC-XXXX-YYY`, a state, a paid date and its transactions.
- **Payment gateways.** A gateway is any service implementing `PaymentGatewayInterface`. The bundle ships `manual`, which records a pending payment and shows the method's instructions, and - with [glitchr/omnitrade](https://github.com/glitchr-omnisense/omnitrade) installed - a bridge to every gateway configured there (Stripe, PayPal, a Shopify or WooCommerce shop): a payment method names one, and that is where the money goes. The same gateways can hold the catalogue (see below).
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
| `marketplace_payment_return` | `/panier/{order}/paiement/{gateway}` |
| `marketplace_payment_webhook` | `/marketplace/{gateway}/webhook` (POST) |
| `marketplace_invoice` | `/factures/{number}` |

The promotions, fees, taxes, shipping and review entities come from latoucheoriginale and are mapped. The shop pages do not use them yet.

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
composer require omnibase/marketplace:dev-main
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
    quick_order: { enabled: true, link_ttl: 2592000 }   # a product bought with an e-mail address, no account
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

Staff see the paid orders waiting at `/admin/commandes/a-expedier` (`marketplace_shipping_queue`, `ROLE_ADMIN`), a screen of the back office; its first address, `/commandes/a-expedier`, redirects there. They mark one shipped with its tracking number, which creates a Shipment, and later delivered. The member follows the parcel on their order page, and a tracking URL may hold `{number}`.

The carrier itself is [glitchr/omnibus](https://github.com/glitchr-omnisense/omnibus)'s: a shipping
method names a gateway configured under `omnibus.gateways` (`gatewayName`), and when that package
is installed `Service\Shipping` books the parcel there (`book()`: the label, the tracking number
on the shipment) and follows it (`track()`). What the buyer pays stays the method's own price,
set by hand; `ratesFor()` says what the carrier's tariff would be. The sender is
`marketplace.shipping.sender` (name, street, postcode, city, country).

## Paying through glitchr/omnitrade

Real money goes through [glitchr/omnitrade](https://github.com/glitchr-omnisense/omnitrade), the
sibling family: one contract for payment providers (`omnitrade/stripe`, `omnitrade/paypal`) and
commerce platforms (`omnitrade/shopify`, `omnitrade/woocommerce`). Install the core and a
provider, register `Omnitrade\Bridge\Symfony\OmnitradeBundle`, configure its gateways:

```bash
composer require glitchr/omnitrade omnitrade/stripe
```

```yaml
# config/packages/omnitrade.yaml
omnitrade:
    gateways:
        card:                        # what a payment method's gatewayFactory names
            factory: stripe
            options:
                api_key: '%env(STRIPE_API_KEY)%'
                webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%'
```

A payment method is a proxy: create one with the gateway `card` (its `gatewayFactory`), and
`PaymentGatewayRegistry` finds, after the application's own gateways, the bridge
`Payment\Omnitrade\OmnitradeGateway` to that omnitrade gateway. The bridge describes the order
as an omnitrade `Payment` (its lines when they add up to the net price, else one line; the
buyer; where to come back), takes the provider's answer as a `PaymentResult` - paid, a page to
send the buyer to, pending, refused - and keeps the provider's reference on the `Transaction`.
`marketplace.gateways.<slug>` still holds what is the method's own (`currencies`, `instructions`,
`method` to insist on a provider's payment method).

The buyer comes back to `marketplace_payment_return`, which asks the provider where the payment
stands; the provider's webhook posts to `marketplace_payment_webhook`, checked and read by the
provider package. Both confirm the same transaction, once (`Checkout::confirm()` is idempotent).
Refunds go back the same way (`OmnitradeGateway::refund()`), as the forge's hour refunds do.

For Stripe, the webhook endpoint - `https://<host>/marketplace/card/webhook` with the Checkout
events - is created by a command, safe to run on every deploy:

```bash
bin/console marketplace:stripe:webhook              # creates it if Stripe lacks it, completes its events otherwise
bin/console marketplace:stripe:webhook --dry-run    # says what it would do
bin/console marketplace:stripe:webhook --recreate   # a new endpoint, hence a new signing secret
```

The API key is the method's `api_key` setting, else `STRIPE_API_KEY`; the address is `--url`, else
the method's `webhook_url`, else the route under the router's default URI. Stripe gives an
endpoint's signing secret only when it is created: the command prints it - put it in
`STRIPE_WEBHOOK_SECRET`, the omnitrade gateway's `webhook_secret`, which is what checks every
event's signature (`stripe listen`'s secret goes there too, locally).

## Brands, typed sheets, lots, the age gate

A product can name its maker (`Brand`: a wine estate, a house - Shopify's vendor, WooCommerce's brand), carry a typed sheet set per taxon without code (`AttributeSet`: which of omnibase's attributes, required, filterable, with a unit), sell by the lot (`packSize`, `minimumQuantity`: a case of 6, a minimum of 12) and to adults only (`ageRestricted` on the product or its taxon: the age gate asks once, 18 by default, 20 in Japan). Pairings, cross-sells and upsells link products (`Product\Association`), and a blog post features products through its connexes. See [docs/catalogue.md](docs/catalogue.md) and [docs/selling-rules.md](docs/selling-rules.md).

## Invoices

An order paid gets its invoice (`Entity\Invoice`, `Service\Invoices::issue()`, by itself with `marketplace.invoice.auto_issue`): a number from a sequence without a gap per series and year (F-2026-000041), the seller and the buyer as they are that day, the order's lines and VAT, the French mentions; a PDF carrying its Factur-X XML (EN 16931, `horstoeko/zugferd`); never changed once issued - a credit note cancels it. The buyer downloads it at `/factures/{number}`, the back office sends it, marks it paid, credits it. Sites create its table by a migration. See [docs/invoices.md](docs/invoices.md).

## Business quotes and exports

A professional asks for a quotation (`/cotation`); the seller prices lines - a product or a free line, by the lot - with the trade's terms (export or import, an Incoterm and its place, the country, the volumes, a date), sends it; the client accepts it and pays its order like any other. An order delivered outside the EU carries no VAT (`Pricing\ExportExemption`, art. 262 I CGI), and a cart that should go there is sent to the quotation form instead. omnibase/forge's quotes (hours) are the same `AbstractQuote`. See [docs/quotes.md](docs/quotes.md).

## Hand-overs, options, starting prices, files

An order collected at the shop on a slot, brought nearby (a list of postcodes) or shipped, followed through a link without an account (`Entity\Order\Pickup`, `Service\Pickups`); the options a buyer chooses on a product - a cooking, extras, a finish - with their surcharge on the line (`Product\OptionGroup`, `Option`, `Service\ProductOptions`); a starting price, "dès 130 € HT" (`Product::getPriceRange()`, `::getStartingPrice()`); files given with a quote request or for an order line, kept out of the public directory (`Entity\Attachment`, `Service\Attachments`); a shipping method without a carrier. See [docs/pickup-and-options.md](docs/pickup-and-options.md).

## A catalogue kept on a platform

Stripe's Products, a Shopify shop, a WooCommerce site: any glitchr/omnitrade gateway that reads a catalogue (`FetchProducts`, `FetchProduct`, `FetchInventory`) can be the source of the shop's products - `marketplace.catalogue.source`, then `bin/console marketplace:catalogue:sync`, and the platform's product webhooks through `/marketplace/{gateway}/webhook`. It replaces the former `src/Shopify` module and its `marketplace.shopify` configuration. See [docs/platform-catalogue.md](docs/platform-catalogue.md).

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

**React to a sale.** Listen for `Base\Marketplace\Event\OrderPaidEvent`. It is dispatched once the order is confirmed and its stock decremented. That is where an app delivers virtual goods, or tells the staff to ship.

**Restyle.** Every class is `.marketplace-*` and driven by custom properties on `.marketplace`. A store's page also carries `.marketplace-store-<slug>`. Override any template under `templates/bundles/MarketplaceBundle/client/`. `_price`, `_product_media` and `_banner` exist for exactly that. Call the original with `@!Marketplace/client/…`.

## Requirements

PHP 8.2+, Symfony 7.4 or 8, Doctrine ORM 3, omnisense/omnibase 3.x and omnibase/admin.

## Licence

See the repository.

## Quick order

A product bought in one step, with no account and no basket: an e-mail
address, the payment.

```twig
{% include '@Marketplace/client/_quick_order.html.twig' with {product: product} %}
```

`Service\QuickOrder::buy()` makes the order for that address (the member it
belongs to, or a new one whose password nobody knows), `Checkout::settle()`
takes the payment with the shop's first able method, and the buyer ends on
the order's own page - a signed link (`QuickOrder::doneUrl()`, valid
`marketplace.quick_order.link_ttl` seconds) that is also good to mail.
`OrderPaidEvent` delivers as for any order. To put something on that page -
files to download, a pickup time - listen to `Event\QuickOrderDoneEvent` and
set a response.

The form is `Form\QuickOrderType` (`marketplace_quick_order_form(product, back)`
gives it to a template of yours), guarded as omnisense/omnibase guards a form -
its option `guard`, action `quick_order`: a trap, the time it takes, the lists,
the captcha when the site has glitchr/omnishield; a signed-in member is asked
no address. A buyer back from the payment provider in the same browser lands
on that page too (`EventListener\QuickOrderReturnListener`). Tested in
`tests/Http/QuickOrderTest.php`.

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
