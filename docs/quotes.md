---
title: Business quotes and exports
order: 22
---

# Business quotes and exports

## The flow

1. A professional asks (`/cotation`, `marketplace_quote_request`; from a
   product's page `?product=12`, from a cart going abroad `?country=JP`):
   who, which company - a French SIRET checked at the State's register, an EU
   VAT number at VIES, or just a name -, which way (`TradeDirection`:
   export, import, domestic), an `Incoterm` (EXW, FCA, FOB, CIF, DAP, DDP) and
   its place, the country, the volumes, a date, their words.
   `marketplace.quotes.recipient` is told by mail.
2. The seller prices it in the back office (Boutique › Cotations, and the
   pipeline `marketplace_admin_quotes`): lines - a product of the catalogue or
   a free line (the transport, a label for the importer) - by the lot
   (`quantity` lots of `lotSize` units at `lotPrice` cents), a discount, a
   date; "Envoyer" mails the client their link (`/cotation/<token>`).
3. The client accepts it, signed in with the address of the request:
   `Service\QuoteToOrder` makes an order at the quote's prices (each lot
   shared out in units when it divides to the cent, the discount in each
   price, a free line a one-off product in no store), delivered where the
   quote says, waiting to be paid like any other - a transfer (the
   `manual` gateway), a card.
4. Paid, the quote is marked paid with the order's reference
   (`EventListener\QuotePaidListener`).

Statuses (`Enum\QuoteStatus`): requested, draft, sent, accepted, paid,
declined.

```yaml
marketplace:
    quotes:
        enabled: true
        store: cave            # the store a quote's order is made in, when neither it nor its products name one
        validity: 30           # days a quote sent holds when no date is set
        recipient: '%env(MAILER_CONTACT)%'
        path: cotation         # the public path: "devis" on a site without omnibase/forge
        phone: true            # the form asks a phone number (optional)
        attachments: true      # and takes files
        consent: false         # true: the data-protection box must be ticked
```

## The form

`/cotation` by default; `marketplace.quotes.path` moves the form and the
quote's page together (`/devis`, `/devis/<token>`) - the route names do not
change. omnibase/forge keeps `/devis` for a studio's quotes: leave
`cotation` where both are installed.

The request takes a **phone number** (`Quote::$phone`) and **files** - a
logo, a photo of the shopfront, a plan (PDF, images, AI, SVG...) - kept out
of the public directory and downloaded from the back office
([files](pickup-and-options.md)); their number, weight and kinds are
`marketplace.attachments.*`. A file refused is said on its field and nothing
is kept.

Robots: the form has a **trap** (a `website` field people do not see): filled,
the sender is thanked and nothing is stored nor mailed. Under the form,
glitchr/omnibase's data-protection notice (`Base\Form\Type\PrivacyType`),
with its box to tick when `consent` is on. `Form\QuoteRequestType` takes the
same as options: `phone`, `attachments`, `trap`, `privacy` (true, a
translation key of the site's own notice, or false), `privacy_consent`,
`privacy_parameters`.

The pipeline is a page of the back office outside the CRUDs: import it in
the application's routes (as omnibase/agenda's calendar page):

```yaml
# config/routes.yaml
marketplace_admin_controller:
    resource: "@MarketplaceBundle/src/Controller/Admin/QuotePipelineController.php"
    type: attribute
```

Routes: `marketplace_quote_request` (/cotation, or `/<path>`), `marketplace_quote`
(/cotation/{token}), `marketplace_quote_accept`, `marketplace_quote_decline`
(POST), `marketplace_quotes` (/mes-cotations), `marketplace_admin_quotes`
(the pipeline, in the back office's nest).

## Exports: no VAT

`Pricing\ExportExemption` (a `VatExemptionInterface`, priority 75: after the
store's own regime, before a reverse charge) exempts an order delivered
outside the EU by a seller inside it, and the order keeps the invoice's
mention: "Exonération de TVA - article 262 I du CGI (livraison à
l'exportation)" for a French seller, article 146 of the VAT directive
otherwise. The seller's country is its store's VAT number's, else
`marketplace.shipping.sender.country`. The customs side - the export
declaration, the excise document (DAE) for alcohol - stays off the site.

## Other trades: AbstractQuote

`Entity\Quote\AbstractQuote` is a mapped superclass: the client and company,
the status, discount, validity, token, `isAcceptable()`, `accept()`,
`markPaid()`. The marketplace's `Quote` adds the trade's terms and lines;
omnibase/forge's `Quote` (hours at a rate, accepted as one `HourPack`) maps it
on its own table, its `QuoteToOrder` extending this one.
`Quote\QuoteRepositoryTrait` (used by each quote repository) numbers them (`saveNumbered()`: D-2026-0001
here, Q-2026-0001 at the forge), finds a client's (`findForClient()`) and
groups them by status (`pipeline()`). `Form\QuoteRequestType` with
`['trade' => false]` leaves out the trade's fields.
