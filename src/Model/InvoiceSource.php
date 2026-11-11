<?php

namespace Base\Marketplace\Model;

use Base\Marketplace\Entity\Store;

/**
 * What an invoice is made from when there is no order: an application's own
 * sale - omnibase/forge's hours, a subscription - given to
 * Service\Invoices::issueFor(), which writes the seller
 * (marketplace.invoice.seller, $store's VAT number over it), the mentions
 * (the exemption, the nature of the operations, these mentions of its own,
 * the payment date, the discount and penalties, the seller's own) and the
 * number, in the shop's series.
 *
 * The buyer, the lines and the totals are given in the shape Entity\Invoice
 * keeps them (Invoice\InvoiceComposer::buyerOf() writes the buyer from a customer;
 * the totals' "exemption" is Invoice\InvoiceComposer::exemption()'s array, or null).
 */
final readonly class InvoiceSource
{
    /**
     * @param array<string, mixed>       $buyer
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>       $totals
     * @param list<string>               $mentions  its own, printed after the nature of the operations (a key "@..." is translated)
     * @param ?string                    $operation goods, services or mixed; null: marketplace.invoice.operation
     * @param ?string                    $sellerName the seller's name when marketplace.invoice.seller gives none (else the store's)
     */
    public function __construct(
        public array $buyer,
        public array $lines,
        public array $totals,
        public ?\DateTimeImmutable $soldAt = null,
        public ?Store $store = null,
        public string $currency = 'EUR',
        public ?string $operation = null,
        public array $mentions = [],
        public ?\DateTimeImmutable $paidAt = null,
        public ?string $sellerName = null,
    ) {
    }
}
