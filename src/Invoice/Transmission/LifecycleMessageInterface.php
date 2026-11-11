<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;

/**
 * Writes the lifecycle message (CDAR) an approved platform takes a status
 * as - "Encaissée" (212) above all, which the reform asks of the seller for
 * the operations whose VAT is due on payment. omnibill's afnor gateway sends
 * the file it is given and writes none (the format is not the family's),
 * and this bundle writes none either: an application that has a writer
 * registers it as a service under this interface (autowired), as it does for
 * omnibase/ledger's Base\Ledger\Omnibill\Invoice\LifecycleMessageInterface.
 *
 * Without one, a status declared to an approved platform stays pending on
 * the invoice (InvoiceFlow::$declared), its reason said in the back office,
 * and marketplace:invoice:status sends it once there is one. omnibill/email
 * needs none: it keeps the statuses itself.
 */
interface LifecycleMessageInterface
{
    /**
     * @param string                                         $status          omnibill's LifecycleStatus value: paid
     * @param string|null                                    $amount          for paid, the amount received, a decimal string
     * @param list<array{rate: float, amount: string}>       $amountsByRate   for paid, that amount by VAT rate, as the payment data asks it
     * @param string|null                                    $recipientAddress the buyer's electronic address the invoice went to
     *
     * @return array{content: string, filename: string}|null null: no message for this one
     */
    public function write(Invoice $invoice, string $status, \DateTimeImmutable $at, ?string $reason = null, ?string $amount = null, array $amountsByRate = [], ?string $recipientAddress = null): ?array;
}
