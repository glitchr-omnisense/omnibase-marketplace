---
title: "Configuration reference"
order: 40
---

# Configuration reference

## The shop's own keys

| Key | Default | Read by |
|---|---|---|
| `default_currency` | `EUR` | prices without a currency of their own (a store's currency wins); a made-up code serves an in-game currency |
| `products_per_page`, `orders_per_page` | 24, 20 | a store's page, the member's orders |
| `cart_max_quantity` | 99 | a cart line; `Product::getMaxQuantity()` can lower it |
| `default_gateway` | `stripe` | the gateway checkout offers first |
| `guest_cart` | false | whether a visitor not signed in may fill a cart |
| `price_tax` | null | the mention after every price: `excl` ("HT"), `incl` ("TTC"), none |
| `quick_order.enabled`, `quick_order.link_ttl` | true, 2592000 | the quick order's form; its signed link's life in seconds |
| `admin_role` | `ROLE_SUPERADMIN` | every marketplace screen of the back office (`MARKETPLACE_VIEW`) |
| `forex.targets` | none | the ISO 4217 codes one Fixer call refreshes |
| `gateways.<slug>` | none | a payment method's own settings: `currencies`, `instructions`, `method`, `webhook_url`... |
| `shipping.sender` | empty | the sender of the parcels booked with omnisense/omnibus |

The other sections are explained with what they serve: `invoice` in
[Invoices and credit notes](invoices.md), `brand_route` and the attributes in
[Brands, typed sheets, filters, pairings](catalogue.md), `age_gate`, the lots
and the zones in [Lots, the age gate, where the shop delivers](selling-rules.md),
`quotes` in [Business quotes and exports](quotes.md), `plans` in
[Plans, subscriptions, rights and credits](plans.md), `wishlist` in
[Lists of wishes](wishlists.md), `referral` in [Referrals](referrals.md),
the suppliers (`supply`) in [Made to order](supply.md), `attachments` and
`pickup` in [Hand-overs, options, starting prices, files](pickup-and-options.md),
`catalogue` in [A catalogue kept on a platform](platform-catalogue.md).

## The whole tree

Dumped with Symfony's `YamlReferenceDumper` from
`Base\Marketplace\DependencyInjection\MarketplaceConfiguration`:

```yaml
marketplace:

    # ISO 4217 code of prices without a currency of their own - or a made-up one for an in-game currency.
    default_currency:     EUR
    products_per_page:    24
    orders_per_page:      20

    # How many of one product a cart line may hold.
    cart_max_quantity:    99

    # The gateway checkout offers first.
    default_gateway:      stripe

    # Whether a visitor who is not signed in may fill a cart.
    guest_cart:           false

    # The mention after every price: "excl" ("HT") for a shop that quotes to businesses, "incl" ("TTC"), null for none. A total paid, VAT in it, says "TTC" whatever this is; a template may say otherwise for one amount (@Marketplace/client/_price.html.twig, price_tax).
    price_tax:            null # One of null; "excl"; "incl"
    quick_order:

        # The form of @Marketplace/client/_quick_order.html.twig takes orders.
        enabled:              true

        # Seconds the signed link of a quick order's page stays valid (30 days).
        link_ttl:             2592000

    # The role (or permission) every marketplace screen of the back office requires: the creators by default.
    admin_role:           ROLE_SUPERADMIN
    forex:

        # ISO 4217 codes, e.g. [JPY, USD]: one Fixer call refreshes them all.
        targets:              []
    gateways:

        # Prototype
        slug:                 ~
    invoice:

        # Issue the invoice of an order as soon as it is paid (OrderPaidEvent). Off: from the back office.
        auto_issue:           false

        # With glitchr/omnibill: the gateway invoices are sent through (omnibill.gateways.<name>; "email" works unconfigured with omnibill/email, from seller.email), their lifecycle statuses kept on them. Null, or without omnibill: the bundle's own e-mail.
        gateway:              email

        # The invoices' series: F-2026-000001. One sequence per series and year, without a gap.
        prefix:               F

        # The credit notes' series: A-2026-000001.
        credit_prefix:        A
        digits:               6

        # Days given to pay an invoice not paid yet (a bank transfer): its due date.
        payment_days:         30

        # The nature of the operations invoiced, a mention required since 2026-09-01: goods, services or mixed.
        operation:            goods

        # "Option pour le paiement de la taxe d'après les débits": the seller opted for it.
        vat_on_debits:        false

        # The rate of the penalties for late payment, and the flat 40 € indemnity: a text or a translation key.
        late_penalties:       '@marketplace.invoice.mention.late_penalties'

        # The terms of a discount for early payment: "Escompte pour paiement anticipé : néant".
        discount:             '@marketplace.invoice.mention.no_discount'

        # Further mentions of the seller's own: an approved association, an insurance, the legal guarantee.
        mentions:             []

        # The seller as the invoices name them, frozen on each one when it is issued.
        seller:

            # Its company name - or for a sole trader, their name.
            name:                 ''

            # SAS, SARL, EURL, EI...
            legal_form:           null

            # "10 000 €": a company's capital.
            share_capital:        null
            street:               []
            postcode:             ''
            city:                 ''
            country:              FR
            siren:                null
            siret:                null

            # "RCS Paris": where it is registered.
            rcs:                  null

            # Its intra-EU VAT number - a store's own (Store::getVatNumber()) wins.
            vat_number:           null
            email:                null
            phone:                null
    shipping:
        sender:
            name:                 ''
            company:              null
            street:               []
            postcode:             ''
            city:                 ''
            country:              FR
            email:                null
            phone:                null

    # The route of a brand's page, given its slug; null: brands have no page.
    brand_route:          null
    age_gate:
        enabled:              true

        # The age asked where nothing more precise is set.
        default:              18

        # By language ("ja") or country ("JP"): {ja: 20, JP: 20}. A country wins over a language.
        ages:

            # Prototype
            key:                  ~

        # The health notice shown with restricted products, e.g. "L'abus d'alcool est dangereux pour la santé, à consommer avec modération." (loi Évin); a translation key works too.
        notice:               null

        # Ask on every page, not only before a restricted product or taxon.
        site:                 false
    quotes:
        enabled:              true

        # Slug of the store a quote's order is made in when neither the quote nor its products name one.
        store:                null

        # Days a quote sent holds when no date is set.
        validity:             30

        # Who is told of a request (default: the base notifier's technical recipient).
        recipient:            null

        # The public path of the quote form and of a quote's page: "cotation" (omnibase/forge keeps /devis for a studio's), "devis" where no forge is installed.
        path:                 cotation

        # The form asks a phone number (optional).
        phone:                true

        # The form takes files (a logo, a photo of the shopfront): kept out of the public directory, downloaded from the back office.
        attachments:          true

        # The data-protection box must be ticked (the notice is shown either way).
        consent:              false

        # With glitchr/omnisign: the gateway (omnisign.gateways.<name>) a priced quote is signed through, in the site's page, to be accepted. Null: accepting is a click, as without omnisign.
        signature:            null

        # A template of the provider's to sign instead of the quote's PDF: DocuSeal's open-source edition signs its own templates only.
        signature_template:   null
    attachments:

        # Where the files are kept: outside the public directory.
        directory:            '%kernel.project_dir%/var/storage/marketplace'

        # Bytes a file may weigh (20 MB).
        max_size:             20971520

        # Files one request or one line may carry.
        max_files:            5

        # What a file may be, by its extension (lower case).
        extensions:

            # Defaults:
            - pdf
            - png
            - jpg
            - jpeg
            - webp
            - gif
            - svg
            - ai
            - eps
            - psd
            - tif
            - tiff
            - zip
    pickup:

        # The ways offered: pickup (at the shop, on a slot), delivery (brought nearby by the shop), shipping (a carrier).
        modes:

            # Default:
            - pickup

        # Where the shop delivers itself: postcodes, or a prefix ("67*").
        zip_codes:            []
        slots:
            from:                 '11:00'
            to:                   '19:00'

            # Minutes a slot lasts.
            step:                 30

        # Minutes the shop needs before the first slot.
        notice:               60

        # Days ahead an order may be for.
        horizon:              14

        # The shop's clock ("Europe/Paris"); null: PHP's, which omnibase sets to the visitor's.
        timezone:             null
    catalogue:

        # The omnitrade gateway the catalogue is read from (stripe, shopify, woocommerce...); null: kept in the back office.
        source:               null

        # Slug of the store the products read are filed in.
        store:                null

        # What the platform writes here; anything else (taxa, pairings, the age gate) stays the site's.
        owned_fields:

            # Defaults:
            - title
            - description
            - price
            - stock
            - availability
            - identifiers
            - brand
            - attributes

        # Read the stock from the platform when it counts it (FetchInventory).
        inventory:            true
    wishlist:

        # The omnitrade gateways asked, in order, to read a pasted address and to write its affiliate link.
        lookup:

            # Defaults:
            - amazon
            - web

        # The omnitrade gateway whose connected accounts the contributions are paid to.
        payout_gateway:       stripe

        # The platform's fee on a contribution: a share of the gift...
        fee_rate:             0.03

        # ...plus this, in minor units.
        fee_fixed:            0

        # The smallest contribution, in minor units.
        minimum:              500

        # Days a reservation holds before its object goes back on the list.
        reservation_days:     30

        # Seconds after which a price read from a shop is read again.
        price_ttl:            86400
    referral:
        enabled:              true

        # Days the referee's first order must hold (the withdrawal period) before the referrer is rewarded.
        cooling_days:         14

        # Rewards a referrer may earn in a calendar month.
        monthly_cap:          10

        # What a new member gets on signing up through a code.
        referee:

            # null: nothing.
            type:                 null # One of null; "coupon"; "credit"

            # A coupon: the code of the discount scope adapter that ties it to its owner.
            scope:                scope-user

            # A coupon: the code of the discount action adapter (a percentage, a fixed amount).
            action:               null

            # A coupon: what the action takes off (0.2 for 20 %, or minor units).
            value:                0
            validity:             '+3 months'
            prefix:               REF
            label:                Referral

            # Credits: their kind.
            credit_kind:          null
            credit_quantity:      0

        # What the referrer gets once the referee's order has held.
        referrer:

            # null: nothing.
            type:                 null # One of null; "coupon"; "credit"

            # A coupon: the code of the discount scope adapter that ties it to its owner.
            scope:                scope-user

            # A coupon: the code of the discount action adapter (a percentage, a fixed amount).
            action:               null

            # A coupon: what the action takes off (0.2 for 20 %, or minor units).
            value:                0
            validity:             '+3 months'
            prefix:               REF
            label:                Referral

            # Credits: their kind.
            credit_kind:          null
            credit_quantity:      0
    supply:
        gelato:
            api_key:              null

            # What Gelato sends as its webhook's authorization header.
            webhook_secret:       null

            # The catalogue products() searches.
            catalog:              cards
        offline:

            # The partner workshop's address: the briefs go there.
            email:                null
            name:                 null

            # Seconds the signed link of a brief stays valid (90 days).
            link_ttl:             7776000
    plans:

        # Days before a right ends when its holder is told (Event\EntitlementEndingEvent).
        reminder_days:        7
```
