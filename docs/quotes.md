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
```

Routes: `marketplace_quote_request` (/cotation), `marketplace_quote`
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
