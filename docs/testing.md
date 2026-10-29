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
Tests: 164, Assertions: 1805, Skipped: 4.
```

The skipped tests need what the image does not have: the quote form's
captcha (omnisense/omnishield and its `fixed` test gateway) and the invoices'
transmission (omnisense/omnibill with omnibill/email).

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
| `Service` | the age gate, attachments, attribute filters, credits, entitlements, invoice transmission, invoices, hand-overs paid and moved, plan purchase, quote signatures, referrals, shipping zones |
| `Supply` | made to order, the Gelato supplier |
| `Translation` | the back office's field labels, the politeness of the catalogues |
| `Wishlist` | lists of wishes |
