<?php

namespace Base\Marketplace\Enum;

/**
 * Who pays the transport and bears the risk, and up to where (Incoterms®
 * 2020) - the six a trade quote meets most. The quote names the place after
 * it: "FOB Le Havre", "DAP Tokyo".
 */
enum Incoterm: string
{
    /** Ex Works: the buyer collects at the seller's cellar. */
    case EXW = 'EXW';
    /** Free Carrier: handed to the buyer's carrier, export cleared. */
    case FCA = 'FCA';
    /** Free On Board: loaded on the ship at the port of departure. */
    case FOB = 'FOB';
    /** Cost, Insurance and Freight: the seller pays sea freight and insurance to the port of arrival. */
    case CIF = 'CIF';
    /** Delivered At Place: delivered at the named place, import duties left to the buyer. */
    case DAP = 'DAP';
    /** Delivered Duty Paid: delivered, import duties and taxes paid by the seller. */
    case DDP = 'DDP';

    /** Whether the seller arranges (and prices) the main carriage. */
    public function sellerShips(): bool
    {
        return \in_array($this, [self::CIF, self::DAP, self::DDP], true);
    }
}
