# Changelog

## [Unreleased]

### Added
- Invoices issued through an approved platform (plan 0, chantier I, the emission side; mandatory for small and medium
  companies on 2027-09-01), with omnisense/omnibill only: `marketplace.invoice.gateway` names an approved platform
  (omnibill/afnor) and `email_gateway` where the invoices that are no e-invoice go. `Enum\InvoiceRoute` tells an
  e-invoice (a company established in France) from a sale to a private person or abroad; an e-invoice is addressed
  through the platform's directory (`Lookup`: the buyer's electronic address in the Factur-X, BT-49, with the seller's,
  BT-34, and the invoicing frame, BT-23), its SIREN checked (form, company register), deposited once, its flow, rule,
  address and file fingerprint kept on `InvoiceFlow`. Its lifecycle: deposited or rejected and why (`refresh()`, the
  webhook), refused, approved or paid by the buyer (`receiveStatuses()`, the shop's own cursor in
  `InvoiceInbox`); shown on the invoice's and the order's screens with what to do; a rejected or refused invoice
  corrected by a credit note and a new invoice (`Invoices::reissue()`, the back office's "reissue"). "Encaissée"
  declared when omnitrade confirms the payment (`InvoicePaidListener`) or at once for an invoice already paid, kept
  pending until the application writes the lifecycle message (`LifecycleMessageInterface`: the CDAR is written
  nowhere here) and sent again by `marketplace:invoice:status`.
- `marketplace.invoice.email_gateway`, `marketplace.invoice.seller.electronic_address`, `electronic_address_scheme`.
- E-reporting (`marketplace.invoice.vat_regime`): `Invoice\Transmission\EReporting` collects, period by period as the
  regime sets them, the sales to private persons by day, category and rate, the sales to companies abroad invoice by
  invoice, and the payments of services, into `Entity\EReport`; submitted to the platform (`Report`) when the
  application writes the file (`EReportWriterInterface`), kept collected otherwise. `marketplace:invoice:ereport`,
  `EReportCrudController`.

### Fixed
- The Factur-X lines are self-consistent: quantity times net price is the line's amount. Quantities and prices take
  the decimals a line needs (`FacturX::decimals()`, up to six and four): an hours line of 4h25 at 90.00 (397.50)
  carries 4.4167 hours, no longer 4.42 (397.80); a line's `unit` (`HUR`) is written, a piece (`H87`) by default.
- `marketplace:invoice:status` without arguments queried fields the invoice does not have (`flowReference`,
  `lifecycleStatus`): it now reads the transmissions not final, then receives the buyers' statuses and sends the
  pending ones.

### Changed
- `InvoiceFlow` (mapped only with omnisense/omnibill; no site has its table yet) gains `rule`, `recipientAddress`,
  `fileSha256`, `declared`; `marketplace_invoice_inbox` and `marketplace_ereport` join it. A site that installs the
  family creates the three with `make:migration`.
- The tests register omnisense/omnibill and omnisense/contracts when the host has them not (`tests/omnibill.php`, from
  `OMNIBILL_PATH`, `~/Sites/omnibill` or `/srv/omnibill`), so that the invoices' transmission tests run in
  omnisense/omnibase's harness: there the test container is compiled again with the family, its tables of the
  transmission created by the tests (`TransmissionSchemaTrait`), and the console processes a test starts get the same
  autoloading (`auto_prepend_file`); `memory_limit` 1536M.

### Changed
- `QuoteCrudController` numbers a new quote in `persistRecord()`, omnibase/admin's write hook on the persistence the site
  chose, in place of the former `persistEntity(EntityManagerInterface)` (deprecated since the admin's step B1a); its
  repository is given by `setQuotes()`. Needs omnibase/admin from its step B1a.
- `OrderCrudController`'s "cart" filter says its condition in the core's finder language (`Filter::matchWith()`: the
  state LIKE, or NOT LIKE, 'ORDER_CART%'), which every persistence reads; `applyWith()` keeps the same condition for a
  list still built on Doctrine's QueryBuilder (omnisense/omnibase 4.x). tests/Controller/OrderCartFilterTest.

### Changed
- Accepts omnisense/omnibase 5.0 (`3.0.*-dev || 4.0.*-dev || 5.0.*-dev`; plan "omnibase agnostic", step S): the bundle runs
  unchanged on it, `Base\Bundle\AbstractBaseBundle` and the other former names it uses being aliases of
  omnibridge/symfony's classes there. The sites on 4.x resolve as before.
- `Product` and `Review::__autocomplete()` render through omnibridge's `Views` (`Runtime::views()`: omnibridge/twig's
  `TwigViews` under Symfony, omnisense/omnibase 4.x step F5a) where the runtime answers it, BaseTrait's deprecated
  `getTwig()` otherwise (the core 3.x): the same template, the same HTML.
- `Brand`, `Order`, `Product`, `Review` and `Store::__toLink()` link through `Base\Contract\Link::to()` (the Urls contract; the same address under
  Symfony, in the core 3.x and 4.x) instead of BaseTrait's `getRouter()->generate()`, which the
  core deprecates since BaseTrait names contracts only (omnisense/omnibase 4.x, step F2f).

### Fixed
- README: the package's name, omnibase/marketplace (the title was base-bundle-marketplace), and
  the omnisense/* families it works with (omnisense/omnitrade, omnisense/omnibus,
  omnisense/omnishield, omnisense/omnistate) in place of their former glitchr/* names and the
  abandoned gitlab.glitchr.dev links; the demo image named omnibase-marketplace-demo.
