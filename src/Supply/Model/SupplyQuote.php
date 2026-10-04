<?php

namespace Base\Marketplace\Supply\Model;

/** What a supply order would cost the shop, in minor units: the making, the posting. */
final readonly class SupplyQuote
{
    public function __construct(
        public int $products,
        public int $shipping,
        public string $currency = 'EUR',
        /** Days until it is delivered, when the supplier says. */
        public ?int $minDays = null,
        public ?int $maxDays = null,
        public ?string $shippingMethod = null,
        public array $raw = [],
    ) {
    }

    public function total(): int
    {
        return $this->products + $this->shipping;
    }
}
