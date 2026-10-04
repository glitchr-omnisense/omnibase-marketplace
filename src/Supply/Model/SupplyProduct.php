<?php

namespace Base\Marketplace\Supply\Model;

/** Something a supplier makes: its reference there (what a Product's supplierReference holds) and its name. */
final readonly class SupplyProduct
{
    public function __construct(
        public string $reference,
        public string $title,
        public array $raw = [],
    ) {
    }
}
