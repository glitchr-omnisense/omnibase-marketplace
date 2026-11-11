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
- it names the invoice it cancels ("Avoir annulant la facture F-2026-000042 du 02/11/2026."), then
  gives the reason, the exemption and the nature of the operations the invoice stated - an invoice
  issued without an order too (`issueFor()`). A reason that already begins with that mention is
  written as it is, once.

The order may then be invoiced again. Partial credit notes do not exist yet.

## An invoice without an order

An application that sells something of its own - omnibase/forge's hours, a subscription - issues its
invoices as the shop does, without an order: `Invoices::issueFor()` takes a `Model\InvoiceSource`
(the buyer, the lines and the totals, in the shape `Invoice` keeps them) and writes the rest from the
same settings as an order's invoice.

```php
$composer = $container->get(InvoiceComposer::class);
$source = new InvoiceSource(
    buyer: $composer->buyerOf($client, $client->getAddress(0)), // its company, SIREN, VAT number, address
    lines: $lines,                                                // ['id', 'label', 'quantity', 'unit_price', 'rate', 'category', 'total', 'vat', ...]
    totals: $totals,                                              // ['lines', 'vat_breakdown', 'total_without_vat', 'vat', 'total', 'exemption', ...]
    soldAt: $periodEnd,                                           // the date the services were completed
    store: $supportStore,                                         // its VAT number over marketplace.invoice.seller's
    operation: 'services',                                        // else marketplace.invoice.operation
    mentions: ['Prestations réalisées du 01/10/2026 au 31/10/2026.'],
);
$preview = $invoices->compose($source);                           // seller, buyer, lines, totals, mentions, sold_at, due_at
$invoice = $invoices->issueFor($source, null, fn (Invoice $invoice) => $hours->billedBy($invoice));
```

- the number is the next of the shop's series (`marketplace.invoice.prefix`), under the same lock;
- the seller is `marketplace.invoice.seller`, the store's VAT number over it (`sellerFor()`);
- the mentions are the order's invoice's, in the same order: the exemption (the totals'
  `exemption`, `InvoiceComposer::exemption()`'s array), the nature of the operations, the source's
  own mentions, VAT on debits, "acquittée le…" (`paidAt`, nothing due) or the due date
  (`payment_days`), the discount, the late penalties to a business, the seller's own;
- the closure is told the invoice inside the numbering's transaction, before it is stored: what it
  bills is marked in the same flush, or not at all;
- an invoice with no line, or that comes to nothing, is refused (`\LogicException`).

It is sent, paid, credited, printed and transmitted as any other invoice (`send()`, `markPaid()`,
`credit()`, `pdf()`). `InvoiceComposer::buyerOf()`, `sellerFor()` and `mentionsFor()` are the pieces
`issueFor()` uses, for an application that writes its own preview.

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

Each line is self-consistent: its quantity (BT-129) times its net price (BT-146), rounded to the
cent, is its net amount (BT-131), the amount billed - never changed to fit. Quantities and prices
are written to two decimals, and to more when a line needs them (`FacturX::decimals()`: up to six
for a quantity, four for a price): an hours line billed from the minutes, 4h25 at 90.00 an hour
(397.50), carries 4.4167 hours - 4.42 would make 397.80 -; three items for 10.00 carry a price of
3.3333. A line gives its unit (`unit`: `HUR` for hours, UN/ECE Recommendation 20; a piece, `H87`,
by default) and, for hours, its `minutes`, which the quantity is counted from. The PDF prints
such a line's quantity in hours and minutes, « 4 h 25 » (the `invoice_quantity` filter), where
the XML keeps 4.4167 `HUR`.

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
  message (CDAR) or an e-reporting file - the application does, through an interface (below).

## Issuing through an approved platform

In France an invoice between two companies established there is an e-invoice: from 2027-09-01 a
small or medium company issues it through an approved platform (plateforme agréée), and receives
them that way since 2026-09-01. The shop changes gateway in its configuration:

```yaml
# config/packages/omnibill.yaml (OmnibillBundle registered, omnibill/afnor installed)
omnibill:
    gateways:
        platform:
            factory: afnor
            options:
                flow_url: '%env(PA_FLOW_URL)%'
                directory_url: '%env(PA_DIRECTORY_URL)%'
                token_url: '%env(PA_TOKEN_URL)%'
                client_id: '%env(PA_CLIENT_ID)%'
                client_secret: '%env(PA_CLIENT_SECRET)%'
                webhook_key: '%env(default::PA_WEBHOOK_KEY)%'
        email:
            factory: email
            options: { from: 'factures@example.org' }

# config/packages/marketplace.yaml
marketplace:
    invoice:
        gateway: platform        # an approved platform: its Capabilities say "approved"
        email_gateway: email     # where the invoices that are no e-invoice go
        seller:
            electronic_address: ~    # its addressing identifier in the directory; its SIREN by default
```

### Which invoices go to the platform

`Enum\InvoiceRoute` tells it from the seller and buyer frozen on the invoice:

| Route | The buyer | Goes |
|---|---|---|
| `b2b` | a company established in France: a SIREN or SIRET, a company name or a VAT number, a French address or none | to the platform, an e-invoice (rule `B2B`) |
| `b2b_int` | a company abroad: a VAT number or a company name, an address outside France | by the e-mail gateway; e-reported invoice by invoice |
| `b2c` | anyone else | by the e-mail gateway; e-reported, aggregated by day |

A company is known by what the customer carries: `getSiren()`, `getSiret()`, `getCompanyName()`,
the VAT number of `VatCustomerInterface` (confirmed through VIES when the customer types it). When
the invoice leaves, its SIREN is checked for its form (Luhn) and against the company register
(omnistate's annuaire-entreprises, `Service\CompanyRegistry`) when the register answers. A seller
outside France is outside the French reform: no route.

### Sent once

`Invoices::send()` asks the platform's directory where the buyer receives
(`InvoiceTransmission::addressing()`, `Lookup`: its SIRET's line, else its legal unit's), writes
that electronic address in the Factur-X (BT-49, scheme 0225) with the seller's (BT-34) and the
invoicing frame (BT-23: B1 goods, S1 services, M1 both; B2, S2, M2 already paid), and submits it
(`POST /v1/flows`, its number as the tracking id). The flow's reference, the rule, the address and
the file's SHA-256 are kept on `InvoiceFlow`. An invoice goes to the platform once: a second send is
refused. Refused before anything leaves: a buyer without a SIREN, a SIREN that is not one or that the
register does not know, a SIREN without an active line in the directory - the message says what to
correct.

A deposit whose answer is lost after it left - a timeout, a 5xx: omnibill's
`OutcomeUnknownException` - may have been taken. It is never sent again blindly: the invoice is kept
as "Outcome unknown" (`InvoiceFlow::OUTCOME_UNKNOWN`) and the platform is asked for its number
(`FindFlow`, the tracking id it was submitted with) at once, by "Refresh its status" and
`marketplace:invoice:status`, and before any new send. Found, it is kept as if the answer had come
back. It is deposited again only once the platform says it has none, `InvoiceTransmission::SEARCH_DELAY`
(ten minutes) after - a flow just deposited may take a moment to show in its search.

A gateway that cannot look it up (no `FindFlow`: Chorus Pro, e-mail), or a lookup whose answer is
none to rely on (the platform refused its search, a `ProviderException`; kept on the row,
`InvoiceFlow::isLookupInconclusive()`), leaves it to a person: the invoice's screen ("On the
platform") says so and offers two answers, once they have looked on the platform
(`Invoices::awaitsConfirmation()`, `InvoiceTransmission::confirmDeposit()`, the CRUD's
`confirmDeposit`):

- **Received by the platform** (« Reçue par la plateforme »), with the flow id the platform shows:
  kept as if its answer had come back, under that reference - what refresh, the webhook and the
  statuses follow it by -, its status asked of the gateway where it can say, deposited otherwise;
- **Absent: deposit again** (« Absente : déposer de nouveau »): the transmission is forgotten and the
  invoice deposited again at once, once.

An unreachable platform, a key refused or a limit reached leave the lookup for later: those answers
are not offered then; and a later lookup that does answer (found, or none) takes it back from the
person.

A declared status whose answer is lost (its message left: "Encaissée") is not sent again by itself
either - `sendPending()` leaves it -, and no platform can be asked for a status message it took. The
invoice's screen shows it as "outcome unknown" with two answers, as omnibase/ledger's suppliers'
invoices do (`Invoices::confirmStatus()`, `InvoiceTransmission::confirmStatus()`, the CRUD's
`confirmStatus`):

- **Received by the platform** (« Reçu par la plateforme »): marked sent, and kept in the invoice's
  history;
- **Absent: send again** (« Absent : renvoyer »): sent again now, once; what the gateway answers is
  kept on it, as for any status.

Both are posts with the admin action's CSRF token, behind the invoices' screen's permission
(`MARKETPLACE_VIEW`), as its other actions.

### Where it stands

- **Deposited (200) or rejected (213)**: the platform's acknowledgement, asked by "Refresh its
  status", `marketplace:invoice:status`, or told by its webhook (`POST
  /marketplace/omnibill/{gateway}/webhook`, the `Afnor-Signature` checked). A rejection keeps the
  platform's reasons (`REJ_SEMAN: … (BT-49)`).
- **Refused (210), approved (205), paid (212) by the buyer**: the platform gives them as lifecycle
  flows coming in. `InvoiceTransmission::receiveStatuses()` reads them from the shop's own cursor
  (`InvoiceInbox`, beside omnibase/ledger's for the suppliers' invoices), from the status command or
  when the webhook announces a flow coming in, and adds each to its invoice's history. It asks for
  the lifecycle flows only (`Receive`'s kinds, omnibill 1.2): the suppliers' invoices are neither
  listed nor downloaded.
- **The back office** shows it on the invoice's screen ("On the platform": the status, the reason,
  what to do) and on its order's ("Invoices").

### A rejected or refused invoice

It is not edited. "Correct: credit note and new invoice" (`Invoices::reissue()`) cancels it with a
credit note and issues the order's invoice again, as the order and its customer stand now. Neither
is sent by itself: correct the cause first (the customer's SIREN, the address), then send them.

### "Encaissée", declared by the shop

When omnitrade confirms the payment of an order whose invoice already went through a gateway
(`OrderPaidEvent`, `Invoice\Transmission\InvoicePaidListener`), the invoice is marked paid and
"Encaissée" (212) is declared, with the amount and its split by VAT rate (`StatusChange::$amounts`,
the rate in basis points, and the writer's `$amountsByRate`). An invoice already paid
when it is submitted has it declared at once. A platform takes a status as a lifecycle message
(CDAR) only, which neither omnisense/omnibill nor this bundle writes: the application registers an
`Invoice\Transmission\LifecycleMessageInterface`. Without one the status is kept pending on the
invoice (`InvoiceFlow::$declared`), the back office says why, and `marketplace:invoice:status` sends
it once there is one. omnibill/email needs none.

## E-reporting

The sales that are no e-invoice are reported to the platform, period by period
(`Invoice\Transmission\EReporting`, `Entity\EReport`), once `marketplace.invoice.vat_regime` is set:

| `vat_regime` | Transactions | Payments |
|---|---|---|
| `normal_monthly` | by ten days, due the 20th, the 30th (February: its last day), the 10th of the next month | monthly, before the 10th of the next |
| `normal_quarterly` (the option, under 4 000 € of VAT a year) | monthly, before the 10th of the next | monthly, before the 10th of the next |
| `simplified` | monthly, by the 25th of the next (the table says "between the 25th and the 30th") | monthly, the same |
| `franchise` | two calendar months, by the 25th of the month after | two calendar months, the same |

What is collected:

- **To private persons**, aggregated by day (the order's payment): for each category - `goods`,
  `services` (from `marketplace.invoice.operation`), `intra_eu` (a distance sale to another EU
  country), `export` (outside the EU, flagged) - and each VAT rate, the base before VAT and the VAT,
  in euros. A credit note takes its amounts off the day it is issued.
- **To companies abroad**, invoice by invoice: the number, the type (380, 381), the dates, the
  invoice corrected, the seller's SIREN and VAT number, the buyer's identifier and country, the
  category (LB, PS, LBPS), the currency, the totals by rate, the exemption, the option for the
  debits; the lines, charges, discounts and delivery address (due from 2027-09-01).
- **Payments**, only for operations whose VAT is due on payment (services, without the option for
  the debits): the amount received by rate, by day for private persons, by invoice for companies
  abroad. An e-invoice's payment is its "Encaissée" status.
- **Warnings**: an amount in another currency (not converted), mixed goods and services, a sale
  abroad not invoiced yet.

The file is the DGFiP's format (its external specifications), which was not read for this bundle:
the application registers an `Invoice\Transmission\EReportWriterInterface`, and the period is
submitted to the platform (`Report`, syntax FRR). Without a writer each period stays collected, its
data on its `EReport` for whoever transmits it. `marketplace:invoice:ereport [--date] [--kind]
[--send]` collects the last period ended (from a cron); the screen `EReportCrudController`
(`MenuItem::linkToCrud(EReport::class, ...)`) shows them, collects again, sends.

## What the law says, and what to confirm

- **Numbering**: "un numéro unique basé sur une séquence chronologique et continue", in distinct
  series "lorsque les conditions d'exercice de l'activité de l'assujetti le justifient", two
  invoices of the same year never sharing a number (CGI ann. II art. 242 nonies A, I-7°; BOFiP
  BOI-TVA-DECLA-30-20-20-10 §70-130, in force since 2013-10-18, read on 2026-11-11). The bundle
  numbers per series and year under a `symfony/lock` lock and the unique key `(series, year,
  sequence)`, in one transaction (tested with six processes at once). The lock needs a store shared
  by every server that issues (a single host's `flock` store otherwise). To confirm with an
  accountant: a series of its own for credit notes (`A-`), and the yearly restart.
- **The e-invoice's data** (impots.gouv.fr, "Données de facture", August 2026): the SIRENs, the
  countries, the category of the operation, the totals by rate, the option for the debits, the
  invoice corrected; from 2027-09-01 the lines, the charges and discounts, the delivery address.
- **The e-reporting's data and periods**: impots.gouv.fr, "Données de transaction", "Données de
  paiement", "Fréquences et délais de transmission" (August 2026; CGI art. 290, 290 A; ann. II art.
  242 nonies M and P).
- **To confirm with the platform**: the invoicing frame's codes (BT-23), the scheme of the
  addresses (0225), its profile (EN 16931 or EXTENDED-CTC-FR), and whether a rejected invoice
  (213), deemed not received, needs a credit note at all or may be deposited again under its
  number.

### Is the Factur-X a French B2B e-invoice?

It is Factur-X 1.0's profile EN 16931 (written by horstoeko/zugferd 1.0.133), valid against the
profile's XSD (`FacturX::validate()`). With an approved platform it carries the electronic addresses
and the invoicing frame. What is not checked here: the Schematron rules of EN 16931 and the French
rules on top of them - horstoeko's `ZugferdKositValidator` runs KoSIT's Java validator with the
German XRechnung scenarios by default, not the French ones. What it may lack for a platform: the
notes' subject codes for the penalties, the indemnity and the discount (the bundle prints them as
plain notes), the option for the debits as a code rather than a note. The platform's validation
report, on the first real deposit, says it.

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

`@Marketplace/invoice/invoice.pdf.twig`, laid out as a French shop's invoice. A site dresses
it in its own `templates/bundles/MarketplaceBundle/invoice/invoice.pdf.twig`, extending the bundle's
one (`{% extends '@!Marketplace/invoice/invoice.pdf.twig' %}`) and filling its blocks: `style`,
`logo` (a data: URI, `|embed_base64`: nothing is fetched from elsewhere), `seller`, `footer`.
Everything it prints comes from the invoice itself, never from today's order or settings.

## For a site

The bundle brings the entity; the site creates its table: `bin/console make:migration`, then
`doctrine:migrations:migrate`. With omnisense/omnibill installed, three more tables are mapped -
`marketplace_invoice_flow`, `marketplace_invoice_inbox`, `marketplace_ereport` -, which the same
migration creates; without the family none exists, and none is needed.

A shop of several sellers is not covered yet: the invoices name one seller
(`marketplace.invoice.seller`), number in one sequence per series and go through one platform
account. Each seller issuing its own would need a sequence of its own, its own platform account (or
the shop's under its delegation: `organization_id`), and its own e-reporting. It requires `dompdf/dompdf`, `horstoeko/zugferd` and `symfony/lock`
(`composer update omnibase/marketplace` installs them).
