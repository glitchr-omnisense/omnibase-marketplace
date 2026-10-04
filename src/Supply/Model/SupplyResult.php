<?php

namespace Base\Marketplace\Supply\Model;

use Base\Marketplace\Enum\SupplyStatus;

/** Where a supply order stands, as its supplier says: its reference there, its state, its parcel. */
final readonly class SupplyResult
{
    public function __construct(
        public string $supplier,
        /** The supplier's id for it. */
        public string $reference,
        public SupplyStatus $status,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public ?string $carrier = null,
        public ?string $message = null,
        /** Ours, when the supplier gives it back. */
        public ?string $orderReference = null,
        public array $raw = [],
    ) {
    }
}
