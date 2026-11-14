---
title: Terms of sale and the right of withdrawal
order: 35
---

# Terms of sale and the right of withdrawal

> An engineering mechanism, not legal advice: which products call for which statement, the
> wording, and whether a buyer is a consumer are the seller's decisions, to take with a lawyer.
> The assumptions this mechanism makes are listed at the end.

A consumer buying at a distance may withdraw within 14 days (French Code de la consommation
L221-18; EU Directive 2011/83/EU). For digital content supplied at once, the right is lost only if
the consumer asked for immediate supply **and** acknowledged losing the right before paying
(L221-28 13°); for a service begun within the 14 days, the consumer must have asked for it
(L221-25), and the right ends once the service is fully performed at their express request,
having acknowledged losing it (L221-28 1°). The trader confirms the contract, these statements
included, on a durable medium (L221-13). And the terms of sale are accepted before the order.

The bundle asks for these statements at checkout, records them on the order, and confirms them
in an e-mail once the order is paid. Nothing changes for a host that names no terms and marks no
product: the checkout is as in 1.0.

## Configuration

```yaml
# config/packages/marketplace.yaml
marketplace:
    consent:
        terms_route: app_terms          # the host's terms of sale page; null: no terms box
        terms_route_parameters: {}
        terms_version: '2026-11-14'     # recorded with each statement, shown in the e-mail
        businesses: ask                 # ask | skip (see below)
        confirmation_email: true
```

## Marking products

`Product::getWithdrawal()` returns an `Enum\Withdrawal`:

| Value | The checkout asks | Typical product |
|---|---|---|
| `none` (default) | nothing more than the terms | goods; anything not marked |
| `digital_content` | "I ask for the digital content to be supplied immediately, as soon as I pay, and I acknowledge that I thereby lose my right of withdrawal." | a licence key, a download |
| `service` | "I ask for the service to begin before the 14-day withdrawal period ends. I acknowledge that if I withdraw, I will pay for what has already been provided, and that I lose my right of withdrawal once the service has been fully performed." | hours credited at once, a job started on payment |

Set it on a product (`$product->setWithdrawal(Withdrawal::DIGITAL_CONTENT)`, kept in the
nullable `withdrawal` column), or let a product class say it by overriding
`defaultWithdrawal()` - omnibase/forge's `LicenseOffer` returns `digital_content`, its `HourPack`
`service`. A variant without one is as its principal. The bundle never infers it from the
product's kind: a plan or a pack of credits is `none` until its seller marks it.

## The checkout

`CartController::Checkout` adds `Form\CheckoutConsentType` (named `consent`) inside its own form,
whose CSRF token covers it:

- `consent[terms]` whenever `terms_route` is set, a link to the terms beside it;
- `consent[immediate_supply]` when a line is `digital_content`;
- `consent[early_service]` when a line is `service`;
- `consent[version]`, hidden: the version of the texts shown (`Consents::version()`).

No box is ticked beforehand and each one is required (`IsTrue`). A box left unticked, or texts
changed between the page and the click (another version), answers the page again with the
errors, status 422; nothing is paid. Once valid, the statements are recorded just before the
payment starts; a new attempt to pay the same cart replaces those of an attempt that did not go
through.

A quick order (`Service\QuickOrder`) asks no statement: a product marked `digital_content` or
`service` is refused there (`quick.error.statements`) and bought from the basket. The terms box
is not asked by the quick order either: a host that sets `terms_route` and keeps quick orders
on should turn them off (`quick_order.enabled: false`) or show its terms beside the form.

## The record

`Entity\Order\Consent`, table `marketplace_order_consent`, one row per statement:

| Column | |
|---|---|
| `order_id` | the order (deleted with it: only an abandoned cart ever is) |
| `statement` | `terms`, `immediate_supply`, `early_service` |
| `text` | the text shown, word for word |
| `hash` | its SHA-256 |
| `locale` | the language it was shown in |
| `url` | the page it links to (the terms) |
| `terms_version` | `consent.terms_version` at the time |
| `channel` | `checkout`, or `hub` (omnibase/forge's one-click purchases) |
| `accepted_at` | when |

No IP address and no user agent are stored: the bundle keeps none anywhere, and the buyer is the
order's customer, signed in. `Consents::of($order)` reads them back.

The table is mapped like every entity of the bundle; the host creates it with a migration, with
the `withdrawal` column of the products:

```bash
bin/console make:migration
bin/console doctrine:migrations:migrate
```

## The confirmation e-mail

On `OrderPaidEvent` (priority -200, after the invoice's), `EventListener\OrderConfirmationListener`
sends `@Marketplace/email/order_confirmation.html.twig` to the buyer of an order that carries
statements: the order's reference, total and lines, each statement word for word with its date,
the terms' link and version. It extends the host's `email.html.twig`, like the invoice's e-mail.
A failure (no mailer, no address, the transport down) is logged and does not undo the payment.
With `confirmation_email: false` the host sends its own; `@Marketplace/email/_consents.html.twig`
(variables `consents`, `terms_version`) lists the statements for it.

## The texts

`@marketplace.consent.*` in the bundle's catalogues (fr, en): `terms`, `immediate_supply`,
`early_service`, the errors, the e-mail. A host words them otherwise in its own
`translations/marketplace+intl-icu.<locale>.yaml`; the text actually shown is what is recorded,
and its hash changes the version, so a client or a page showing the older wording is asked again.
Japanese has no consent texts: they fall back as the catalogue does.

## Businesses

`businesses: ask` (default) asks every buyer the withdrawal statements. `skip` leaves them out
for a buyer whose customer class implements `Model\VatCustomerInterface` with a confirmed VAT
number (`hasConfirmedVatNumber()`), taken as a business buying for its trade; the terms are
still asked. Whether that is the right test is a legal question (a business may buy for private
purposes; a sole trader may have no VAT number).

## Assumptions, for review

1. The wording of the three statements (fr, en) is a draft.
2. One box for "immediate supply + loss of the right" (digital content), one for "early start +
   pay pro rata + loss once fully performed" (services): the two legal elements are combined in one
   box each.
3. The boxes are required: an order of digital content is not sold with the right of withdrawal
   kept. A seller who wants to offer that choice needs another flow (supply deferred to day 15).
4. "Supplied immediately" is the seller's mark per product; the bundle does not check that
   supply really starts at payment.
5. The e-mail is the durable medium; it carries the statements and a link to the terms, not the
   terms themselves (no PDF attached).
6. Records live as long as the order. Retention period and deletion on a customer's request are
   the host's policy.
7. Proof of consent: the signed-in account, the timestamp, the exact text and its hash. No IP
   address or device data (data minimisation).
