---
title: Plans, subscriptions, rights and credits
order: 60
---

# Plans, subscriptions, rights and credits

A shop that sells **access** rather than things: a pass bought once, a
subscription, a pack of credits. A plan is a product; paying it grants its
buyer a right (`Entitlement`); what the right allows is read through
`Service\Entitlements`; units to spend later are kept in a ledger
(`Credit`). Rights and credits are generic: any bundle hangs its own on them
(a course's access, hours of work) rather than keeping tables of its own.

## Installation

Nothing to enable. The update adds two columns to the products' table
(`kind`, `plan`) and four tables (`marketplace_subscription`,
`marketplace_entitlement`, `marketplace_usage`, `marketplace_credit`):

```
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

Subscriptions need `glitchr/omnitrade` with its subscriptions (1.x) and a
provider that offers them (`omnitrade/stripe`); passes and credit packs need
nothing more than a payment method.

```yaml
# config/packages/marketplace.yaml
marketplace:
    plans:
        reminder_days: 7     # days before a right ends when its holder is told
```

```
# crontab: every night
0 3 * * *  php bin/console marketplace:subscriptions --sync
```

## A plan is a product

```php
use Base\Marketplace\Enum\ProductKind;
use Base\Marketplace\Model\PlanTerms;

$plan = new Product(null, $store, 4900, 'EUR');
$plan->setTitle('Formule B');
$plan->setKind(ProductKind::PLAN);
$plan->setPlan(new PlanTerms(
    billing: PlanTerms::ONE_TIME, months: 18,                 // a pass: 18 months of access (null: for good)
    grants: ['events.major' => 1, 'events.extra' => 2, 'guests' => 150, 'list' => true, 'commission' => 0.025],
    credits: ['stationery' => 2000],                          // credited when paid
    level: 2,                                                 // a higher level is an upgrade
));

$monthly = (new Product(null, $store, 1900, 'EUR'))->setKind(ProductKind::PLAN)
    ->setPlan(new PlanTerms(PlanTerms::RECURRING, 'month', grants: ['ai' => true], credits: ['ai' => 200], level: 4));

$pack = (new Product(null, $store, 900, 'EUR'))->setKind(ProductKind::CREDIT_PACK)
    ->setPlan(new PlanTerms(credits: ['ai' => 100]));
```

| `PlanTerms` | |
|---|---|
| `billing` | `one_time` (a pass) or `recurring` (a subscription) |
| `interval` | of a subscription: `day`, `week`, `month`, `year` |
| `months` | of a pass: how long the right lasts; null: for good |
| `grants` | the rights by key: a count or a ceiling (integer), a switch (boolean), a rate or any value |
| `credits` | units credited at each payment, by kind |
| `level` | how plans compare |

A plan sold by size is a product with **variants**: each has its price, and
its own terms are laid over its principal's (`['grants' => ['guests' => 150]]`
on the variant "up to 150 guests"). Plans go through the cart like any
product: coupons and promotions apply.

## What happens when it is paid

`EventListener\PlanPurchaseListener` (on `OrderPaidEvent`):

- each **plan** in the order becomes an `Entitlement` of the buyer - its
  code the product's slug (its principal's for a variant), its grants, its
  end (`months` from now) - and `EntitlementGrantedEvent` is dispatched; a
  quantity of 2 makes two entitlements, whose counts add up;
- its `credits` are credited (they lapse with the pass);
- each **credit pack** credits its kinds, times the quantity (no expiry).

An order made of one recurring plan is not paid once: the omnitrade bridge
**subscribes** (`Subscribe`, the plan's interval). Paid, the listener keeps
the provider's subscription as an `Entity\Order\Subscription`, and the
entitlement lasts as long as it runs. The provider's events
(`customer.subscription.*`) reach `/marketplace/{gateway}/webhook` and are
applied by `Service\Subscriptions::apply()`: a renewal moves the period's
end and credits the plan's credits again, a payment that fails makes it
`past_due` (the right is kept while the provider retries), its end ends the
right.

```php
$subscriptions->cancel($subscription);                          // at the end of the period paid
$subscriptions->cancel($subscription, atPeriodEnd: false);      // at once
return $this->redirect($subscriptions->portalUrl($subscription, $returnUrl, 'fr'));   // card, invoices: the provider's page
```

`marketplace:subscriptions` announces, once each, the rights that ended
(`EntitlementEndedEvent`: the application archives, offers an export) and
those that end within `reminder_days` (`EntitlementEndingEvent`: it writes
to their holder); `--sync` first reads each running subscription from its
provider.

## Asking what somebody may do

```php
public function __construct(private Entitlements $rights) {}

$rights->allows($user, 'list');                    // a switch on in one active entitlement - or a count with some left
$rights->limit($user, 'guests');                   // a ceiling: the largest among them (null: none)
$rights->value($user, 'commission', 0.03);         // a value: the highest-level entitlement's
$rights->remaining($user, 'events.extra');         // a count: all of them, less what was used

$rights->consume($user, 'events.extra', 1, 'occasion', $occasion->getId());   // takes one; EntitlementException when none is left
$rights->release($user, 'events.extra', 'occasion', $occasion->getId());      // gives it back
$rights->coveredBy($user, 'events.major', 'occasion', $id);                   // the entitlement that thing took its unit from
```

A refusal (`EntitlementException`, its `$key`) is an answer to go on from -
offer the upgrade -, not a failure: the EntityManager stays open.

### A right over one thing

```php
$rights->grant($pupil, 'course', [], null, ['type' => 'classroom_course', 'id' => $course->getId(), 'order' => $order]);
$rights->holds($pupil, 'course', 'classroom_course', $course->getId());
```

`grant()` takes the holder, a code, the grants, an expiry and a context
(`type` / `id` of the resource, `product`, `order`, `subscription`, `level`,
`startsAt`): the same call serves a gift made by hand in a back office.

## Credits

```php
public function __construct(private Credits $credits) {}

$credits->balance($user, 'ai');
$credits->grant($user, 'ai', 100, 'welcome', new \DateTimeImmutable('+1 year'));
$spent = $credits->spend($user, 'ai', 1, 'an answer', 'occasion', $id);       // CreditException when the balance is short
$credits->refund($spent, 'the answer failed');
```

The balance is what the grants still valid gave, less what was spent: what
a lapsed grant had left is lost, and what was spent comes out of the lapsed
grants first. `spend()` locks the holder's row while it reads the balance.

## Before a payment leaves: `PaymentPreparingEvent`

Dispatched by the omnitrade bridge just before the provider is asked: a
listener may send the money to a connected account and keep a fee
(`destination`, `applicationFee` - Stripe Connect), make or change a
subscription (`interval`, `price`, `trialDays`, `customer`), add `metadata`,
set the `description` and the `locale` of the provider's page.

```php
#[AsEventListener]
final class SellerPayout
{
    public function __invoke(PaymentPreparingEvent $event): void
    {
        $event->destination = $event->order->getStore()->getManager()?->getPayoutAccount();
        $event->applicationFee = (int) round($event->order->getNetPrice() * 0.08);
    }
}
```

A webhook that is neither an order's payment nor the catalogue's is
dispatched as `PaymentNotificationEvent` (a connected account, a
subscription, a payment of the application's own): whoever it concerns sets
its outcome.
