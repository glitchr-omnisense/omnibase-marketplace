---
title: Referrals
order: 80
---

# Referrals

Each member has a code; somebody who signs up through it is their referee
and may get a welcome; once the referee's first order is paid and has held
for the cooling-off period, the referrer is rewarded - a coupon of the shop
or credits. For any store.

## Installation

Three tables (`marketplace_referral_code`, `marketplace_referral`,
`marketplace_referral_reward`): a migration.

```yaml
# config/packages/marketplace.yaml
marketplace:
    referral:
        enabled: true
        cooling_days: 14          # the withdrawal period: the referee's order must hold that long
        monthly_cap: 10           # rewards a referrer may earn in a calendar month
        referee:                  # on signing up through a code
            type: coupon          # coupon, credit, or null (nothing)
            action: action-item-percentage-amount   # the shop's discount action adapter, by its code
            value: 0.2            # 20 %
            validity: '+3 months'
            prefix: BIENV
            label: 'Bienvenue'
        referrer:                 # once the referee's order has held
            type: credit
            credit_kind: stationery
            credit_quantity: 1000
```

```
# crontab: every night
45 3 * * *  php bin/console marketplace:referrals
```

A coupon is made on the shop's own discount adapters, found by their codes
(`scope`, `scope-user` by default: the coupon is its owner's alone;
`action`): they must exist in the database (the fixtures of a shop seed
them), else no coupon is handed out and the referral goes on without.
Each coupon is used once.

## In the application

The bundle has no page: the link, the sign-up and the mails are the
application's.

```php
public function __construct(private Referrals $referrals) {}

// The member's link: /p/{code}
$code = $referrals->codeFor($user)->getCode();

// On /p/{code}: keep it for the sign-up.
if ($referrals->find($code)) { $session->set('referral', $code); }

// Once the new member exists:
try {
    $referral = $referrals->attribute($session->get('referral'), $newUser);
} catch (ReferralException $e) {
    // $e->getMessage(): disabled, unknown, self, email, already, customer
}

$referrals->referralsBy($user);   // those who came through their code, with their status
$referrals->rewardsOf($user);     // what they earned
$referrals->reject($referral);    // in review, before its reward leaves
```

The rest happens by itself: `EventListener\ReferralListener` (on
`OrderPaidEvent`) qualifies a referee's first paid order,
`marketplace:referrals` rewards the referrers whose referee's order has held
`cooling_days`. `ReferralRewardedEvent` is dispatched for each reward (the
referee's welcome, the referrer's thanks): the application writes to its
beneficiary.

| Status | |
|---|---|
| `pending` | signed up through the code, nothing bought yet |
| `qualified` | first order paid; the reward waits for the cooling-off period |
| `rewarded` | the referrer was rewarded |
| `rejected` | refused: its `rejection` says why |

## What is refused

| Rejection | Why |
|---|---|
| `self`, `email` (at sign-up, a `ReferralException`) | the same account; the same address once its `+tag` and a Gmail's dots are taken off |
| `already`, `customer` (at sign-up) | a member is referred once; somebody who already bought is no referee |
| `card` | the order was paid with a means the referrer also paid with |
| `refunded` | the order was refunded or cancelled during the cooling-off period |
| `cap` | the referrer reached `monthly_cap` |
| `review`, or a listener's own | `Referrals::reject()`; `ReferralQualifyingEvent::$rejection` |

The card is compared by the `fingerprint` kept in a transaction's details.
The omnitrade bridge does not record one by itself: an application that
wants this check reads it from its provider (Stripe:
`payment_method_details.card.fingerprint` of the charge) and sets
`ReferralQualifyingEvent::$fingerprint`, or keeps it in the transaction's
details under `fingerprint`.
