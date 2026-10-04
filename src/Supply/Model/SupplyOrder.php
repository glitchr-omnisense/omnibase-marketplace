<?php

namespace Base\Marketplace\Supply\Model;

/** What a supplier is asked: lines, for one recipient. */
final readonly class SupplyOrder
{
    /**
     * @param list<SupplyLine>      $lines
     * @param array<string, scalar> $metadata
     */
    public function __construct(
        /** Ours, unique: the supplier keeps it and gives it back ("CMD-1042-1"). */
        public string $reference,
        public array $lines,
        public SupplyRecipient $recipient,
        public string $currency = 'EUR',
        /** The supplier's shipping method, when one is insisted on; else its cheapest. */
        public ?string $shippingMethod = null,
        /** Who the order is for, for the supplier's records. */
        public ?string $customerReference = null,
        public array $metadata = [],
    ) {
    }
}
