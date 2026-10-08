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

## Accepting by signing it (glitchr/omnisign)

With [glitchr/omnisign](https://github.com/glitchr-studio/omnisign) installed, glitchr/omnibase's
`Base\Service\Signatures` there, and a gateway named, accepting a priced quote is **signing it in
the page**:

```yaml
marketplace:
    quotes:
        signature: contracts          # a gateway of omnisign.gateways; absent (the default): accepting is a click
        signature_template: ~         # DocuSeal's open-source edition signs its own templates only: one's id
```

1. "Signer et accepter" sends the quote's PDF (`@Marketplace/quote/quote.pdf.twig`, laid out as the
   invoice, a box "Bon pour accord" on its last page where the signature goes) to the provider, as an
   envelope about the quote, and takes the client to the provider's signing page. Nothing is
   accepted yet. Asked again while it waits, the same envelope is signed.
2. Signed, the client comes back to `/cotation/<token>/signee` (`marketplace_quote_signed`): the
   provider is asked where it stands and, completed, the quote is accepted as a click accepts it -
   its order, its payment (`Service\QuoteToOrder`). The provider's webhook
   (`/signatures/{gateway}/webhook`, glitchr/omnibase) accepts it too, for its client, if they never
   come back.
3. Declined, expired or cancelled: the quote stays open, its page says so, and the client may sign
   again (a new envelope).
4. The signed quote and its evidence (the provider's audit trail) are kept with the envelope in the
   private storage; the quote's page links them for its client, the back office's quote screen for
   the shop (`/cotation/<token>/signature/document|preuve`, `marketplace_quote_signature_file`).

A site dresses the PDF in `templates/bundles/MarketplaceBundle/quote/quote.pdf.twig`, keeping the
signature's box where `Quote\Signature\QuoteSignatures::BOX` says (points from the top left corner of
the last page). With DocuSeal's open-source edition, which signs only its own templates,
`signature_template` names one: the quote is then what the site's page shows, not the signed
document.

Without the family, without the core's `Signatures`, or without `signature`, accepting is a click
as it always was: nothing in `src/Quote/Signature/` is registered, and nothing of the bundle
implements an omnisign interface.

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

Robots: the form is guarded as glitchr/omnibase guards a form - its option
`guard` (`action: quote`; glitchr/omnibase's `docs/20-architecture/guard.md`):
a trap, the time it takes (a signed stamp), the lists of
`base.guard.reputation`, the captcha when the site has glitchr/omniguard
(without it, the trap and the time alone). A robot is refused on the form;
nothing is stored nor mailed. Under the form, glitchr/omnibase's
data-protection notice (`Base\Form\Type\PrivacyType`), with its box to tick
when `consent` is on. `Form\QuoteRequestType` takes the same as options:
`phone`, `attachments`, `privacy` (true, a translation key of the site's own
notice, or false), `privacy_consent`, `privacy_parameters`, and the core's
`guard`.

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
