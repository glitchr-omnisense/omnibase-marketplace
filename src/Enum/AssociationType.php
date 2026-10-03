<?php

namespace Base\Marketplace\Enum;

/** How one product leads to another on its page. */
enum AssociationType: string
{
    /** Goes with it: a dish for a wine, a cheese for a sake. */
    case PAIRING = 'pairing';
    /** Bought with it: a carafe, glasses, another vintage. */
    case CROSS_SELL = 'cross_sell';
    /** Better than it: the grand vin above the second wine. */
    case UPSELL = 'upsell';
}
