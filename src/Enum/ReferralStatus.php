<?php

namespace Base\Marketplace\Enum;

enum ReferralStatus: string
{
    /** The referee signed up through the code; nothing bought yet. */
    case PENDING = 'pending';
    /** Their first order is paid: the referrer's reward waits for the cooling-off period. */
    case QUALIFIED = 'qualified';
    /** The referrer was rewarded. */
    case REWARDED = 'rewarded';
    /** Refused: an abuse (the same person, the same card), the month's ceiling, a refund, a review. */
    case REJECTED = 'rejected';
}
