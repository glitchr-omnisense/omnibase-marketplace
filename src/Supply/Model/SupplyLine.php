<?php

namespace Base\Marketplace\Supply\Model;

/**
 * One thing to make: the supplier's own product (a Gelato productUid, a
 * partner's reference), how many, and the files it is printed from - one
 * per printable side, as public addresses the supplier fetches.
 */
final readonly class SupplyLine
{
    /**
     * @param list<string>         $files   addresses of the print files (PDF, PNG)
     * @param array<string, mixed> $options the personalisation in words: a text, a monogram, a paper
     */
    public function __construct(
        /** Ours: the order item's id. */
        public string $reference,
        public string $productReference,
        public int $quantity = 1,
        public array $files = [],
        public ?string $title = null,
        public array $options = [],
    ) {
    }
}
