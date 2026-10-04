<?php

namespace Base\Marketplace\Enum;

enum ContributionStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
}
