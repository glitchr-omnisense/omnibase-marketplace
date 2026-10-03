<?php

namespace Base\Marketplace\Enum;

/** Which way the goods of a trade quote travel, seen from the seller. */
enum TradeDirection: string
{
    /** From the seller's country to the buyer's (Bordeaux → Tokyo). */
    case EXPORT = 'export';
    /** From abroad to the buyer, the seller sourcing (Japanese sake for a Paris restaurant). */
    case IMPORT = 'import';
    /** Within the seller's country, or within the EU. */
    case DOMESTIC = 'domestic';
}
