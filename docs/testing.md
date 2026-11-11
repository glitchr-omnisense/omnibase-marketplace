---
title: "Testing"
order: 60
---

# Testing

## Running the suite

Inside a host application:

```bash
php vendor/bin/phpunit -c vendor/omnibase/marketplace/phpunit.xml.dist
```

In omnisense/omnibase-harness, mounting the checkout in place of the
installed package:

```bash
docker run --rm --network none \
    -v "$PWD":/srv/demo/vendor/omnibase/marketplace:ro \
    omnisense/omnibase-harness:latest test marketplace
```

On this checkout, in the harness:

```text
Tests: 183, Assertions: 1823, Skipped: 23.
```

The skipped tests need what the image does not have: the quote form's
captcha (omnisense/omnishield and its `fixed` test gateway) and the invoices'
transmission (omnisense/omnibill with omnibill/email and omnibill/afnor).
`tests/omnibill.php` registers the family from `OMNIBILL_PATH`, a checkout
beside the workspace (`~/Sites/omnibill`) or `/srv/omnibill`, and
omnisense/contracts from `OMNISENSE_CONTRACTS`, `~/Sites/omnisense-contracts`
or `/srv/omnisense-contracts`; in the harness it compiles the test container
again with them, and the console processes a test starts get it as their
`auto_prepend_file`:

```bash
docker run --rm --network none \
    -v "$PWD":/srv/demo/vendor/omnibase/marketplace:ro \
    -v ~/Sites/omnibill:/srv/omnibill:ro \
    -v ~/Sites/omnisense-contracts:/srv/omnisense-contracts:ro \
    omnisense/omnibase-harness:latest test marketplace
```

```text
Tests: 183, Assertions: 1949, Skipped: 2.
```

The platform's tests (`Service/InvoicePlatformTest`, `Service/EReportingTest`)
run omnibill's real afnor gateway on a `MockHttpClient` that records each
request and answers as XP Z12-013's OpenAPI describes: no platform is called.

## What the tests cover

| Folder | Tests |
|---|---|
| `Attribute` | `OrderReferenceTest`: an order's reference |
| `Catalogue` | `PlatformSynchronizerTest`: a platform's catalogue read into the shop |
| `Controller` | `CrudRequiredFieldsTest`: omnibase/admin's `CrudFieldsTestCase` on the screens |
| `Entity` | availability, barcodes, lots, options, SKUs, quotes |
| `Http` | the price mention, the product form and screens without script, a product's page, the quick order, the quote request, the shipping method's form without script |
| `Model` | `PlanTermsTest`: a plan's terms |
| `Payment` | the demo gateways, omnitrade's demo, the dev gateway, the omnitrade bridge, `PaymentPreparingEvent` |
| `Pricing` | `ExportExemptionTest`: VAT on exports |
| `Service` | the age gate, attachments, attribute filters, credits, entitlements, invoice transmission, invoices through an approved platform (the directory, one deposit, deposited, rejected and corrected, refused by the buyer from the shop's cursor, the webhook, "Encaissée" pending then sent through the application's writer, a sale to a private person by e-mail), the e-reporting (each regime's periods and deadlines, the day's sales to private persons, a sale abroad, the payments of services, kept without a writer, submitted once with one, its screen), invoices, hand-overs paid and moved, plan purchase, quote signatures, referrals, shipping zones |
| `Supply` | made to order, the Gelato supplier |
| `Translation` | the back office's field labels, the politeness of the catalogues |
| `Wishlist` | lists of wishes |
