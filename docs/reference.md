---
title: "Reference"
order: 45
---

# Reference

## Routes

| Name | Path | Methods | Access |
|---|---|---|---|
| `marketplace_stores` | `/boutiques` | any | everyone |
| `marketplace_store` | `/boutique/{slug}` | any | everyone |
| `marketplace_product` | `/boutique/{store}/{slug}` | any | everyone |
| `marketplace_age_gate` | `/age` | POST | everyone ([selling rules](selling-rules.md)) |
| `marketplace_attachment` | `/marketplace/piece-jointe/{id}` | GET | a product's attachment ([files](pickup-and-options.md)) |
| `marketplace_cart` | `/panier` | any | `ROLE_USER` |
| `marketplace_cart_add` | `/panier/ajouter/{id}` | POST | `ROLE_USER` |
| `marketplace_cart_update` | `/panier/{order}/ligne/{item}` | POST | `ROLE_USER` |
| `marketplace_cart_files` | `/panier/{order}/ligne/{item}/fichiers` | POST | `ROLE_USER` |
| `marketplace_cart_coupon` | `/panier/{order}/coupon` | POST | `ROLE_USER` |
| `marketplace_cart_coupon_remove` | `/panier/{order}/coupon/{coupon}/retirer` | POST | `ROLE_USER` |
| `marketplace_checkout` | `/panier/{order}/commander` | any | `ROLE_USER` |
| `marketplace_payment_return` | `/panier/{order}/paiement/{gateway}` | any | the buyer back from the provider |
| `marketplace_payment_webhook` | `/marketplace/{gateway}/webhook` | POST | the provider, checked by its omnitrade package |
| `marketplace_orders` | `/commandes` | any | `ROLE_USER` |
| `marketplace_order` | `/commandes/{reference}` | any | `ROLE_USER` |
| `marketplace_quick_order` | `/commande-express/{id}` | POST | everyone, when `quick_order.enabled` |
| `marketplace_quick_order_done` | `/commande-express/merci/{reference}` | any | the signed link |
| `marketplace_pickup` | `/remise/{token}` | GET | the hand-over's token ([hand-overs](pickup-and-options.md)) |
| `marketplace_invoice` | `/factures/{number}` | GET | the invoice's buyer ([invoices](invoices.md)) |
| `marketplace_invoice_webhook` | `/marketplace/omnibill/{gateway}/webhook` | POST | the invoicing gateway |
| `marketplace_quote_request` | `/%marketplace.quotes.path%` | GET, POST | everyone ([quotes](quotes.md)) |
| `marketplace_quote` | `/%marketplace.quotes.path%/{token}` | any | the quote's token |
| `marketplace_quote_accept`, `marketplace_quote_decline` | `.../{token}/accepter`, `.../{token}/refuser` | POST | `ROLE_USER` |
| `marketplace_quote_signed`, `marketplace_quote_signature_file` | `.../{token}/signee`, `.../{token}/signature/{kind}` | GET | `ROLE_USER` |
| `marketplace_quotes` | `/mes-cotations` | any | `ROLE_USER` |
| `marketplace_company_lookup` | `/api/company/{number}` | GET | a company's registration number (9 to 20 digits) |
| `marketplace_supply_offline` | `/marketplace/fabrication/{reference}` | GET, POST | a supplier without an API ([made to order](supply.md)) |
| `marketplace_supply_webhook` | `/marketplace/supply/{supplier}/webhook` | POST | the supplier |
| `marketplace_shipping_queue` | `/admin/commandes/a-expedier` | GET | `ROLE_ADMIN` |
| `marketplace_shipping_queue_legacy` | `/commandes/a-expedier` | GET | redirects to the queue |
| `marketplace_order_ship`, `marketplace_order_deliver` | `/commandes/{reference}/expedier`, `/commandes/{reference}/livree` | POST | `ROLE_ADMIN` |
| `marketplace_admin_quotes` | `/admin/marketplace/quotes` | GET | `MARKETPLACE_VIEW` |

The back office's screens follow `marketplace.admin_role` (`ROLE_SUPERADMIN`
by default) through the `MARKETPLACE_VIEW` voter attribute. Their own actions:
the exchange rates' `refresh`; an invoice's `send`, `refresh`, `paid` and
`credit`; an order's `invoice`; a quote's `send`.

## Twig functions and filters

| Name | Kind | Returns |
|---|---|---|
| `marketplace_cart_count()` | function | the items in the member's carts (quantities summed) |
| `marketplace_price_with_vat(product, region = null, order = null)` | function | a product's price with its VAT, in minor units |
| `marketplace_vat_rate(product, region = null, order = null)`, `marketplace_vat_rates(order)` | function | the rate that applies to a product; the rates of an order |
| `marketplace_price_tax()` | function | `marketplace.price_tax`: the mention after a price |
| `marketplace_quick_order_form(product, back)` | function | the quick order's form |
| `marketplace_tracking_url(method, number)` | function | a shipment's tracking address, `{number}` replaced |
| `marketplace_order_invoices(order)` | function | an order's invoices and credit notes |
| `marketplace_age_gate()`, `marketplace_age_minimum()`, `marketplace_age_notice()` | function | whether to ask the visitor's age, the age, the notice |
| `marketplace_attribute_set(product)`, `marketplace_attributes(...)` | function | a product's typed sheet and its values |
| `marketplace_associations(...)`, `marketplace_products_of(...)` | function | pairings, a brand's or a set's products |
| `invoice_money`, `invoice_rate` | filter | an amount and a rate as an invoice prints them |

## Commands

| Command | Arguments and options | Does |
|---|---|---|
| `marketplace:stripe:webhook` | `--url`, `--recreate`, `--dry-run` | creates the Stripe webhook endpoint if missing |
| `marketplace:catalogue:sync` | `[gateway]`, `--full`, `--since`, `--query`, `--inventory`, `--dry-run` | reads a platform's catalogue into the shop ([a catalogue kept on a platform](platform-catalogue.md)) |
| `marketplace:invoice:issue` | `[orders...]`, `--paid-without-invoice` | issues the invoices of paid orders ([invoices](invoices.md)) |
| `marketplace:invoice:status` | `[numbers...]` | asks the invoicing gateway where the invoices sent stand |
| `marketplace:subscriptions` | `--sync` | ends the rights that ran out, announces those about to ([plans](plans.md)) |
| `marketplace:referrals` | | rewards the referrers whose referee's first order held ([referrals](referrals.md)) |
| `marketplace:wishlist:refresh-prices` | `--limit=200` | reads the lists' prices again, expires lapsed reservations ([lists of wishes](wishlists.md)) |

## Events

| Event | When |
|---|---|
| `OrderPaidEvent` | an order has just been paid in full, its stock decremented |
| `PaymentPreparingEvent` | an order is about to be handed to an omnitrade gateway: the last moment to say how |
| `PaymentCancelledEvent` | a payment did not happen (the member turned back, the provider let it expire): the order is a cart again; a listener may set the response |
| `PaymentNotificationEvent` | a provider's webhook about something else than an order or the catalogue |
| `QuickOrderDoneEvent` | a quick order's page is about to be shown to the holder of its link; a listener may set the response |
| `PickupChangedEvent` | a hand-over moved (received, accepted, ready, done...) |
| `CatalogueNotificationEvent` | a platform said a product or a stock level changed |
| `SubscriptionChangedEvent` | a subscription's state moved (renewed, past due, set to stop, ended), as the provider told |
| `EntitlementGrantedEvent`, `EntitlementEndingEvent`, `EntitlementEndedEvent` | a right granted, about to end, ended |
| `ReferralQualifyingEvent`, `ReferralRewardedEvent` | a referee's first order is paid (a listener may refuse the referral); a referrer rewarded |
| `ContributionPreparingEvent`, `ContributionPaidEvent` | a contribution to a list about to be paid, paid |
| `SupplyJobChangedEvent` | a made-to-order job moved at its supplier |

All are in `Base\Marketplace\Event\`.

## Extension points

| Tag or interface | For |
|---|---|
| `PaymentGatewayInterface` (autoconfigured) | a payment gateway of the application's own |
| `marketplace.trial_gateway_factory` | a trial's gateway ([plans](plans.md)) |
| the VAT regimes (`StoreVatRegime`, `ExportExemption`, `ReverseCharge`, by priority) | how a line's VAT is decided |
| `PaymentKeysSection` | the payment keys on omnibase/admin's API keys page ([the dev gateway and the keys](dev-gateway-and-keys.md)) |
