<?php

namespace Base\Marketplace\Event;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An order is about to be handed to an omnisense/omnitrade gateway: the last
 * moment to say how. A listener may
 *
 *   - send the money to a connected account (Stripe Connect) and keep a fee:
 *     $destination ("acct_..."), $applicationFee (minor units, within the
 *     order's net price);
 *   - make it a subscription: $interval (day, week, month, year),
 *     $intervalCount, $price (a price kept at the provider), $trialDays,
 *     $customer (the provider's customer when the buyer already is one) -
 *     the bridge sets these itself for an order of one recurring plan;
 *   - add metadata the provider keeps and gives back on its webhooks, change
 *     the description shown on its page, its language;
 *   - charge a method the provider keeps for the buyer, with no page
 *     (omnisense/omnitrade 1.1's Payment::$savedMethod): $savedMethod, and
 *     $customer, the provider's customer it belongs to; $returnUrl is then
 *     where the bank sends its buyer back after its own check (3-D Secure),
 *     the marketplace's return page when null.
 */
final class PaymentPreparingEvent extends Event
{
    public ?string $destination = null;
    public ?int $applicationFee = null;

    public ?string $interval = null;
    public int $intervalCount = 1;
    public ?string $price = null;
    public ?int $trialDays = null;
    public ?string $customer = null;

    /** A method the provider keeps for $customer (SavedMethod::$reference), charged off-session. */
    public ?string $savedMethod = null;
    /** Where the buyer comes back from the provider's page; null: the marketplace's return page. */
    public ?string $returnUrl = null;

    /** @param array<string, scalar> $metadata */
    public function __construct(
        public readonly Order $order,
        public readonly Transaction $transaction,
        public readonly PaymentMethod $method,
        /** The omnitrade gateway's name. */
        public readonly string $gateway,
        public array $metadata = [],
        public ?string $description = null,
        public ?string $locale = null,
    ) {
    }

    public function isSubscription(): bool
    {
        return null !== $this->interval;
    }
}
