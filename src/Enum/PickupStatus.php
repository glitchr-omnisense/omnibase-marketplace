<?php

namespace Base\Marketplace\Enum;

/**
 * Where a hand-over stands, in the order the shop moves it along:
 *
 *     checkout ─▶ received ─▶ accepted ─▶ preparing ─▶ ready ─▶ (out_for_delivery) ─▶ done
 *                     └─▶ refused        └── cancelled | refunded, from anywhere before done
 */
enum PickupStatus: string
{
    /** The order exists, its payment has not come yet. */
    case CHECKOUT = 'checkout';
    /** Paid (or placed): waiting for the shop's word. */
    case RECEIVED = 'received';
    case ACCEPTED = 'accepted';
    case PREPARING = 'preparing';
    case READY = 'ready';
    /** On its way: the shop's own round, or the carrier's. */
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DONE = 'done';
    case CANCELLED = 'cancelled';
    case REFUSED = 'refused';
    case REFUNDED = 'refunded';

    /** @return list<self> the steps a tracking page shows, for this mode */
    public static function flow(PickupMode $mode = PickupMode::PICKUP): array
    {
        $flow = [self::CHECKOUT, self::RECEIVED, self::ACCEPTED, self::PREPARING, self::READY, self::OUT_FOR_DELIVERY, self::DONE];

        return PickupMode::PICKUP === $mode ? array_values(array_filter($flow, fn (self $s) => self::OUT_FOR_DELIVERY !== $s)) : $flow;
    }

    public function isOpen(): bool
    {
        return !\in_array($this, [self::DONE, self::CANCELLED, self::REFUSED, self::REFUNDED], true);
    }

    public function trans(): string
    {
        return 'pickup.status.'.$this->value;
    }
}
