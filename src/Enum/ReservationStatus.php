<?php

namespace Base\Marketplace\Enum;

enum ReservationStatus: string
{
    /** Promised: nobody else may take it, until it expires. */
    case HELD = 'held';
    /** Bought: it no longer expires. */
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    /** It keeps the object off the list. */
    public function holds(): bool
    {
        return self::HELD === $this || self::CONFIRMED === $this;
    }
}
