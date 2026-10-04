<?php

namespace Base\Marketplace\Supply;

use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyProduct;
use Base\Marketplace\Supply\Model\SupplyQuote;
use Base\Marketplace\Supply\Model\SupplyResult;

/**
 * Somebody who makes what the shop sells and sends it to the buyer - a
 * print-on-demand service, a local workshop. A Product names its supplier
 * (Product::$supplier) and its reference there; once an order is paid,
 * Service\Supply hands its lines to their suppliers.
 *
 * Services implementing this interface are tagged marketplace.supplier and
 * found by SupplierRegistry: an application adds a supplier by writing a
 * class. One that does not do something throws SupplyException.
 */
interface SupplierInterface
{
    /** What a Product's supplier holds: "gelato", "offline". */
    public static function name(): string;

    /** Whether it has what it needs to run (a key, an address). */
    public function isConfigured(): bool;

    /** @return list<SupplyProduct> what it makes, matching a search when given */
    public function products(?string $query = null): array;

    /** What the order would cost the shop. */
    public function quote(SupplyOrder $order): SupplyQuote;

    /** Sends the order: launched, or as a draft the supplier keeps until confirmed. */
    public function submit(SupplyOrder $order, bool $draft = false): SupplyResult;

    /** Launches a draft. */
    public function confirm(string $reference): SupplyResult;

    public function fetch(string $reference): SupplyResult;

    public function cancel(string $reference): SupplyResult;

    /**
     * A webhook of the supplier's, read; null when it concerns no order.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function notify(string $body, array $headers = []): ?SupplyResult;
}
