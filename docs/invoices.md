---
title: "Invoices and credit notes"
order: 33
---

# Invoices and credit notes

`Entity\Invoice` is the shop's invoice and its credit note (avoir). `Service\Invoices` issues them,
cancels them, sends them and prints them. The PDF carries its Factur-X XML.

```php
$invoice = $invoices->issue($order);              // F-2026-000041: the order paid, frozen
$pdf = $invoices->pdf($invoice);                  // PDF/A-3, factur-x.xml attached
$invoices->send($invoice);                        // mailed to the buyer, PDF attached: "sent"
$creditNote = $invoices->credit($invoice, 'Colis retourné');   // A-2026-000003: the invoice cancelled
```

## What an invoice is

- **Its number** is the next of a sequence without a gap, one per series and year: `F-2026-000041`
  (`marketplace.invoice.prefix`, `digits`), `A-2026-000003` for credit notes (`credit_prefix`). The
  law asks for "a chronological and continuous sequence", and allows several series.
  `Invoice\InvoiceNumbering` takes the number and stores the invoice in one transaction, under a
  `symfony/lock` lock on the series (`marketplace.invoice.F`). Two invoices issued at the same moment
  never share a number, and no number is taken without its invoice. The table's unique key
  `(series, year, sequence)` holds even if the lock fails.
- **Its seller and buyer** are copied onto it the day it is issued, and kept as they were then:
  - the seller is `marketplace.invoice.seller` (name, legal form, capital, address, SIREN, SIRET, RCS,
    VAT number), with the store's own VAT number taking precedence;
  - the buyer is the customer: their company name or name, the billing address, their SIREN and
    SIRET when their User has them (`getSiren()`, `getSiret()`), and their VAT number
    (`VatCustomerInterface`). The delivery address is copied too when it differs from the billing one.
- **Its lines and amounts** come from the order, VAT included: each line's price before VAT and its
  VAT are read from `OrderItem`, never computed again.
  - The order's own charges (shipping, service, other taxes) and its order-level discount carry no
    VAT in the order. They are amounts the buyer paid VAT included. Each one is split between the
    rates of the lines, in proportion to their bases: an accessory charge follows the rate of what
    it accompanies.
  - The invoice's total is exactly what the order cost.
  - The VAT breakdown by rate, the total before VAT, the VAT, the total, what was paid and what
    remains due are all kept on the invoice.
- **Its mentions** are written in the seller's language (French for a French seller):
  - the order's VAT exemption, if any (`TVA non applicable, art. 293 B du CGI`, `Autoliquidation…`,
    `Exonération… article 262 I du CGI`);
  - the nature of the operations (`marketplace.invoice.operation`: goods, services, mixed);
  - the option for VAT on debits (`vat_on_debits`);
  - the payment: "acquittée le…", or the due date (`payment_days`);
  - the discount for early payment (`discount`, "néant" by default);
  - for a business buyer, the late payment penalties and the 40 € flat indemnity
    (`late_penalties`);
  - then the seller's own mentions (`mentions`).
- **Its state** is issued, then sent (`send()`, or `markSent()`), paid, or cancelled by a credit
  note. The invoice of an order already paid is born paid.

**An issued invoice does not change.** Doctrine refuses any update of it except its state
(`Invoice::MUTABLE`). A correction is a credit note (`credit()`), which cancels the whole invoice:
- it copies the invoice's seller, buyer, lines and amounts;
- it takes its own number in the credit notes' series;
- it names the invoice it cancels.

The order may then be invoiced again. Partial credit notes do not exist yet.

## The mentions the law asks for, and where they come from

The invoice prints what the administration lists:

- **Its number and date.**
- **The date of the sale:** the order's payment.
- **The seller:** name, legal form, capital, address, SIREN, RCS, VAT number.
- **The buyer:** their name, address, SIREN and VAT number when they are a business, and the delivery
  address when it differs.
- **Each line:** its designation, quantity, unit price before VAT, VAT rate, and amounts before and
  after VAT.
- **The charges and discounts.**
- **The VAT by rate, and the totals before and after VAT.**
- **Payment:** the payment date or due date, the discount for early payment, and the late payment
  penalties and 40 € indemnity.
- **The exemption mentions.**
- **Since 2026-09-01:** the buyer's SIREN, the delivery address, the nature of the operations, and the
  option for VAT on debits.

Sources:

- Service-public.gouv.fr, *Factures : mentions obligatoires*,
  <https://entreprendre.service-public.gouv.fr/vosdroits/F31808> (page updated on 11 August 2026,
  read on 8 October 2026).
- Code général des impôts, article 289, and its annex II, article 242 nonies A (the mentions, the
  continuous numbering).
- Code de commerce, articles L441-9 (the invoice between professionals) and L441-10 (late payment
  penalties, the 40 € indemnity).

A seller with special mentions adds them in `marketplace.invoice.mentions`: an approved association,
a craftsperson's insurance, the legal guarantee of conformity.

## Factur-X

The PDF carries its data as Factur-X 1.0 with the **EN 16931** profile ("COMFORT"), written by
`horstoeko/zugferd` (`Invoice\FacturX`). The file is a PDF/A-3 with the UN/CEFACT CII XML attached
as `factur-x.xml`.

Why EN 16931 rather than another profile:

- EN 16931 is the European standard's own model, and the French reform's base set of data is
  defined on it.
- MINIMUM and BASIC WL are not invoices under French law.
- BASIC drops what a line here may say.
- EXTENDED adds nothing a line here holds.

The XML is checked against the profile's schema (`FacturX::validate()`, the library's XSD
validator). The Schematron rules of EN 16931 (KoSIT's validator, which needs Java) are not run here.

Today's French reform requires the reception of electronic invoices from 2026-09-01, and their
emission from 2027-09-01 for small and medium companies. Sending goes through `omnisense/omnibill`
when it is installed (below); until the shop must use an approved platform, its `omnibill/email`
gateway sends the invoice by e-mail, its PDF attached.

## Sending through omnisense/omnibill

With `omnisense/omnibill` installed, `Invoices::send()` (the invoices' screen, "Send") hands the
invoice to an omnibill gateway, and keeps where it stands there beside it
(`Invoice\Transmission\Entity\InvoiceFlow`: the gateway, its reference, the lifecycle statuses of
the reform - sent, deposited, refused, paid... - with their codes 200 to 213).

```yaml
# config/packages/marketplace.yaml
marketplace:
    invoice:
        gateway: email           # the default; null: this bundle's own e-mail, as without omnibill
```

- **`email`, by default, with nothing to configure**: with `omnibill/email` installed and no omnibill
  gateway of that name, the invoice is e-mailed from the seller's address
  (`marketplace.invoice.seller.email`) to the buyer, with this bundle's subject and text (the
  signed link included) and the PDF attached. Its statuses are kept by hand, beside the invoice:
  "Mark paid" declares it "Encaissée" (212), the status the reform makes mandatory.
- **A site's own gateway**: `marketplace.invoice.gateway` names one of `omnibill.gateways` (the
  `OmnibillBundle` registered). An `email` one keeps its own `from`, `subject`, `body`, `bcc`; this
  bundle's words fill in what it leaves out. An approved platform (`omnibill/afnor`) is a change
  of configuration, not of code - the day the shop must issue through one.
- **Where it stands**: the invoices' screen shows the latest status beside the state ("sent ·
  Encaissée (email)") and asks the gateway again ("Refresh its status");
  `bin/console marketplace:invoice:status [<number>...]` does it for every invoice transmitted whose
  status is not final (a cron, for a platform without webhooks); a platform's webhook goes to
  `POST /marketplace/omnibill/{gateway}/webhook` (`marketplace_invoice_webhook`), its signature
  checked by the gateway.
- **Without the family**, nothing of it exists: the folder `src/Invoice/Transmission/` is not
  registered and its entity not mapped (a site has no `marketplace_invoice_flow` table, and needs
  none); `send()` mails the invoice itself. A site that installs the family creates the table:
  `make:migration`, then `doctrine:migrations:migrate`.
- **Not done here**: receiving the suppliers' invoices (`omnibase/ledger`), writing a lifecycle
  message (CDAR) for a platform that wants one to declare a payment - the status is then kept on
  the shop's side only.

## Configuration

```yaml
# config/packages/marketplace.yaml
marketplace:
    invoice:
        auto_issue: false        # true: issued when the order is paid (OrderPaidEvent)
        prefix: F                # F-2026-000001
        credit_prefix: A         # A-2026-000001
        digits: 6
        payment_days: 30         # the due date of an invoice not paid yet
        operation: goods         # goods, services, mixed
        vat_on_debits: false
        late_penalties: '@marketplace.invoice.mention.late_penalties'   # a text or a translation key
        discount: '@marketplace.invoice.mention.no_discount'
        mentions: []
        seller:
            name: "Atelier Exemple"
            legal_form: SAS
            share_capital: "10 000 €"
            street: ["12 rue Exemple"]
            postcode: "75011"
            city: Paris
            country: FR
            siren: "123456789"
            siret: "12345678900012"
            rcs: "RCS Paris"
            vat_number: FR40123456789
            email: factures@example.org
```

## Where it shows

- **The buyer:**
  - signed in, they download their invoice at `/factures/{number}` (`marketplace_invoice`,
    `InvoiceVoter`);
  - anyone holding its signed link can open it too (`Invoices::signedUrl()`, the link the e-mail
    carries).
- **The back office:**
  - the orders' screen has "Issue the invoice" for an order paid without one;
  - the invoices' screen (`InvoiceCrudController`) shows the PDF and lets staff send it, mark it
    paid, or cancel it with a credit note. Nothing is edited there.
- **The command line:** `bin/console marketplace:invoice:issue <reference>...` issues those orders'
  invoices; `--paid-without-invoice` issues every paid order's that has none.

## The PDF's look

`@Marketplace/invoice/invoice.pdf.twig`, laid out after La Touche Originale's invoice. A site dresses
it in its own `templates/bundles/MarketplaceBundle/invoice/invoice.pdf.twig`, extending the bundle's
one (`{% extends '@!Marketplace/invoice/invoice.pdf.twig' %}`) and filling its blocks: `style`,
`logo` (a data: URI, `|embed_base64`: nothing is fetched from elsewhere), `seller`, `footer`.
Everything it prints comes from the invoice itself, never from today's order or settings.

## For a site

The bundle brings the entity; the site creates its table: `bin/console make:migration`, then
`doctrine:migrations:migrate`. It requires `dompdf/dompdf`, `horstoeko/zugferd` and `symfony/lock`
(`composer update omnibase/marketplace` installs them).
