# Changelog

## [Unreleased]

## [1.1.1] - 2026-11-14

### Fixed

- The statements were shown, recorded and e-mailed in English on a site in another language: `Consents` took the
  translator's language only from a translator implementing `LocaleAwareInterface`, which omnisense/omnibase's
  `Base\Service\Translator` does not, and fell back on a fixed `'en'`. The language is now the one asked, else the
  current request's, else the translator's (any translator that says it), else `kernel.default_locale`
  (`Consents::locale()`). Each statement's `locale` is the language its text was actually found in (the catalogues'
  fallbacks followed), so a record's `locale` is always the language of its `text`.
- `Consents` takes two optional trailing arguments, `RequestStack` and `kernel.default_locale` (autowired).
- `CheckoutConsentTest`: a French page shows, records (text, hash, `fr`) and confirms the French texts; each
  statement says the language its text is in (`fr_FR` is `fr`, an unknown language its fallback). docs/withdrawal.md,
  "The texts".

## [1.1.0] - 2026-11-14

### Added

- What a buyer states before paying, recorded and confirmed (docs/withdrawal.md). An engineering mechanism prepared
  for a lawyer's review, its assumptions listed there. Backward compatible: nothing is asked until the host names
  its terms or marks a product.
  - `marketplace.consent`: `terms_route` (+ `terms_route_parameters`), `terms_version`, `businesses` (`ask` |
    `skip`), `confirmation_email`.
  - `Enum\Withdrawal` (`none`, `digital_content`, `service`) and `Product::getWithdrawal()` / `setWithdrawal()`,
    kept in a new nullable column `withdrawal`; a variant is as its principal, a product class says its own by
    `defaultWithdrawal()`.
  - The checkout (`Form\CheckoutConsentType`, inside its form): the terms of sale's box whenever `terms_route` is
    set, the immediate supply's box for digital content (Code de la consommation L221-28 13°), the early start's
    for a service (L221-25, L221-28 1°); unticked, each one required, the version of the texts shown checked. A box
    missing or texts changed: the page again, 422, nothing paid.
  - `Entity\Order\Consent` (table `marketplace_order_consent`): each statement word for word, its SHA-256, its
    language, the terms' link and version, the channel (`checkout`, `hub`), when. No IP address, no user agent.
  - `Service\Consents`: the statements an order asks (`statementsFor()`), their version, `record()`, `of()` - what
    omnibase/forge's one-click purchases use as well.
  - `EventListener\OrderConfirmationListener`: an order paid that carries statements is confirmed to its buyer by
    e-mail (`@Marketplace/email/order_confirmation.html.twig`, the durable medium of L221-13);
    `@Marketplace/email/_consents.html.twig` for a host's own e-mail.
  - The texts in `@marketplace.consent.*`, fr and en.
  - `CheckoutConsentTest`.

### Changed

- A quick order refuses a product marked `digital_content` or `service` (`quick.error.statements`): it asks no
  statement; the basket does.
- The bundle's classes extend omnibridge/symfony's `AbstractBaseBundle`, `AbstractBaseExtension` and
  `AbstractBaseConfiguration` by their names (`Omnibridge\Symfony\Omnibase\Bundle\*`), no longer by the former
  `Base\Bundle\*` ones, which omnisense/omnibase keeps only as aliases until 6.0. The same classes: nothing changes
  for an application. The example application registers `Omnibridge\Symfony\Omnibase\BaseBundle` (`Base\BaseBundle`
  is its former name).
- `OrderCrudController`'s "cart" filter says its condition with `Filter::matchWith()` only: its `applyWith()`
  (Doctrine's QueryBuilder, deprecated by omnibase/admin since its step B1b) is gone. With omnisense/omnibase 5.1 and
  `OrderRepository`, which reads the finder, the admin builds the orders' list through the finder and never called
  it. Only an application's controller extending `OrderCrudController` and overriding one of the admin's deprecated
  QueryBuilder hooks (`createIndexQueryBuilder()`, `applySearch()`, `applyFilters()`) had the filter written on the
  QueryBuilder; it moves to `indexCriteria()`. `OrderCartFilterTest` drops its QueryBuilder case.

### Schema

- New table `marketplace_order_consent`, new nullable column `withdrawal` on the products' table: the host's
  migration (`make:migration`).

## [1.0.0] - 2026-11-13

### Changed

- Installs with the default minimum-stability: stable requirements (`omnisense/omnibase` ^5.1, `omnibridge/symfony`
  ^1.1, `omnibase/admin` ^1.0, `omnisense/omnistate`, `omnistate/vies` and `omnistate/annuaire-entreprises` ^1.0), a
  branch alias, no `minimum-stability: dev` left to the sites, no vcs repository.
- `omnibridge/doctrine` ^1.0, `doctrine/doctrine-bundle` and `symfony/doctrine-bridge` required: the repositories
  extend omnibridge/doctrine's `ServiceEntityRepository`, the admin forms use `EntityType`.
- require-dev and suggested: `omnisense/omnitrade` ^1.1 (saved methods), `omnisense/omnibus` ^2.0.
- CI: glitchr-studio/ci's package workflow (the bundle installed alone, composer validate, PHPUnit, PHPStan against
  its baseline, a consumer install without require-dev) and its release workflow.

### Added
- A saved method charged with no page (omnisense/omnitrade 1.1's `Payment::$savedMethod`): a
  `PaymentPreparingEvent` listener sets `$savedMethod` and `$customer` (the provider's customer it
  belongs to), and may set `$returnUrl`, where the bank sends its buyer back after its own check
  (3-D Secure), in place of the marketplace's return page. The bridge passes them only to an
  omnitrade that knows saved methods; the rest of the payment is built as before, and
  `Checkout::settle()` takes the answer as for any payment (paid, refused, or a redirect to the
  bank's page, the webhook then confirming the order). `PaymentPreparingTest`.
- The billing period of services: a line gives `'period' => ['start' => 'Y-m-d', 'end' => 'Y-m-d']`, the invoice's
  period follows (`Invoice::getBillingPeriod()`, the earliest start to the latest end). The Factur-X carries it
  (BT-73/BT-74) and each line's (BT-134/BT-135); the PDF prints « Période de facturation : du … au … », and when every
  line is a service (`Invoice::isServices()`) its date reads « Date de réalisation des prestations » in place of
  « Date de la vente ». Lines without a period (an order's) read as before. No schema change. `BillingPeriodTest`.
- An invoice without an order: `Invoices::issueFor(InvoiceSource $source, ?\DateTimeImmutable $at, ?\Closure $issued)`
  issues what an application sells of its own (omnibase/forge's hours, a subscription) in the shop's series, under the
  same lock: `Model\InvoiceSource` gives the buyer, lines, totals, completion date, store, nature of the operations and
  mentions of its own; marketplace writes the seller and every mention from its settings, as for an order's invoice.
  `Invoices::compose()` shows what it would issue. `InvoiceComposer::sellerFor()`, `buyerOf()`, `mentionsFor()` and
  `creditMention()` are the order-free pieces `seller()`, `buyer()` and `mentions()` now call; their output for an
  order is unchanged, but for a blank VAT number (`VatCustomerInterface`), read as none. `InvoiceSourceTest`.
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

- omnisense/omnibill 1.2 adopted. A deposit whose answer is lost after it left (`OutcomeUnknownException`) is kept as
  `InvoiceFlow::OUTCOME_UNKNOWN` and looked up by the invoice's number (`FindFlow`) at once, on refresh, by the
  webhook and before any new send: deposited again only once the platform says it has none, `SEARCH_DELAY` after. A
  declared status whose answer is lost is not sent again by itself (`InvoiceFlow::declaredUnknown()`); an e-reporting
  period is looked up by its trackingId the same way (`EReporting::OUTCOME_UNKNOWN`). `receiveStatuses()` asks for the
  lifecycle flows only (`Receive`'s kinds): the suppliers' invoices are no longer downloaded. "Encaissée" carries the
  amount received at each VAT rate (`StatusChange::$amounts`).
- What the platform cannot settle after an answer lost is settled by a person on the invoice's screen. A deposit whose
  outcome is unknown on a gateway that cannot look it up (no `FindFlow`), or whose lookup answered nothing to rely on
  (a `ProviderException`, kept as `InvoiceFlow::isLookupInconclusive()`), no longer stays blocked: « Reçue par la
  plateforme » with the platform's flow id keeps it as deposited, « Absente : déposer de nouveau » deposits it again,
  once (`InvoiceTransmission::confirmDeposit()`, `Invoices::awaitsConfirmation()`, `confirmDeposit`). A declared
  status whose answer was lost: « Reçu par la plateforme » marks it sent, « Absent : renvoyer » sends it again, once
  (`InvoiceTransmission::confirmStatus()`, `confirmStatus`), as omnibase/ledger's suppliers' invoices do. Both POST
  with the admin action's CSRF token, translated en and fr.

### Fixed
- The credit note of an invoice without an order (`issueFor()`, or its order deleted since) names the invoice it
  cancels, then the reason, the exemption and the nature of the operations that invoice stated
  (`InvoiceComposer::operationOf()`): it carried the reason alone. A reason that already begins with that mention (what
  omnibase/forge passed) is written once. `InvoiceSourceTest`.
- The Factur-X lines are self-consistent: quantity times net price is the line's amount. Quantities and prices take
  the decimals a line needs (`FacturX::decimals()`, up to six and four): an hours line of 4h25 at 90.00 (397.50)
  carries 4.4167 hours, no longer 4.42 (397.80); a line's `unit` (`HUR`) is written, a piece (`H87`) by default.
- The invoice's PDF printed an hours line's quantity as the raw decimal (4.4167): a line that gives its `minutes`
  (unit `HUR`) now reads « 4 h 25 » there (`invoice_quantity`); the Factur-X XML keeps 4.4167 `HUR`.
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
