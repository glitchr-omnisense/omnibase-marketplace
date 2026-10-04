<?php

namespace Base\Marketplace\Enum;

/** Where something made to order stands at its supplier. */
enum SupplyStatus: string
{
    /** Sent as a draft: priced, checked, not launched. */
    case DRAFT = 'draft';
    /** Sent; the supplier has not answered yet. */
    case SUBMITTED = 'submitted';
    case ACCEPTED = 'accepted';
    case IN_PRODUCTION = 'in_production';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    /** Refused by the supplier, or could not be sent: its message says why. */
    case FAILED = 'failed';

    public function isFinal(): bool
    {
        return \in_array($this, [self::DELIVERED, self::CANCELLED, self::FAILED], true);
    }
}
