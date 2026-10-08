<?php

namespace Base\Marketplace\Invoice;

use Base\Marketplace\Entity\Invoice;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use Symfony\Component\Intl\Currencies;

/**
 * An invoice's Factur-X: its data as the structured XML of EN 16931
 * (UN/CEFACT CII, Factur-X 1.0's profile EN 16931 - "COMFORT"), and the PDF
 * that carries it, as horstoeko/zugferd writes them (PDF/A-3, the XML
 * attached as factur-x.xml).
 *
 * The profile: EN 16931 is the European standard's core model, the one the
 * French reform's base set of data is defined on; MINIMUM and BASIC WL are
 * not invoices for French law, BASIC drops what a line of the shop may say
 * (its allowances), EXTENDED adds what no line here holds.
 *
 * Amounts are written in the currency's unit (19.90), from the invoice's
 * integers in its smallest unit (1990).
 */
final class FacturX
{
    public const PROFILE = ZugferdProfiles::PROFILE_EN16931;

    public static function available(): bool
    {
        return class_exists(ZugferdDocumentBuilder::class);
    }

    public function xml(Invoice $invoice): string
    {
        return $this->document($invoice)->getContent();
    }

    /** The invoice's PDF, the XML attached: what is sent and kept. */
    public function attach(Invoice $invoice, string $pdf): string
    {
        $builder = new ZugferdDocumentPdfBuilder($this->document($invoice), $pdf);
        $builder->setDeterministicModeEnabled(true);

        return $builder->generateDocument()->downloadString();
    }

    /** @return list<string> what the schema finds wrong in this XML (EN 16931's XSD): none for a valid one */
    public function validate(string $xml): array
    {
        $validator = (new ZugferdXsdValidator(ZugferdDocumentReader::readAndGuessFromContent($xml)))->validate();

        return array_values(array_map('strval', $validator->validationErrors()));
    }

    /** The XML a PDF carries, null when it carries none. */
    public static function extract(string $pdf): ?string
    {
        try {
            return ZugferdDocumentPdfReader::getXmlFromContent($pdf);
        } catch (\Throwable) {
            return null;
        }
    }

    private function document(Invoice $invoice): ZugferdDocumentBuilder
    {
        $money = fn (int|float $amount): float => round($amount / 10 ** Currencies::getFractionDigits($invoice->getCurrency()), 4);
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $totals = $invoice->getTotals();
        $exemption = $totals['exemption'] ?? null;

        $document = ZugferdDocumentBuilder::createNew(self::PROFILE);
        $document->setDocumentInformation($invoice->getNumber(), $invoice->isCreditNote() ? '381' : '380', $invoice->getIssuedAt(), $invoice->getCurrency());
        foreach ($invoice->getMentions() as $mention) {
            $document->addDocumentNote($mention);
        }
        if ($invoice->getOrderReference()) {
            $document->setDocumentSellerOrderReferencedDocument($invoice->getOrderReference());
        }
        if ($credited = $invoice->getCredited()) {
            $document->setDocumentInvoiceReferencedDocument($credited->getNumber(), null, $credited->getIssuedAt());
        }

        // The seller: its name, address, SIREN (scheme 0002) and VAT number.
        $document->setDocumentSeller((string) $seller['name']);
        $document->setDocumentSellerAddress(...self::address($seller));
        if (!empty($seller['siren']) || !empty($seller['siret'])) {
            $document->setDocumentSellerLegalOrganisation((string) ($seller['siren'] ?? substr((string) $seller['siret'], 0, 9)), '0002', (string) $seller['name']);
        }
        if (!empty($seller['vat_number'])) {
            $document->addDocumentSellerVATRegistrationNumber((string) $seller['vat_number']);
        }
        if (!empty($seller['email'])) {
            $document->setDocumentSellerCommunication('EM', (string) $seller['email']);
        }

        // The buyer.
        $document->setDocumentBuyer((string) ($buyer['name'] ?? $buyer['email'] ?? '-'));
        $document->setDocumentBuyerAddress(...self::address($buyer['address'] ?? []));
        if (!empty($buyer['siren']) || !empty($buyer['siret'])) {
            $document->setDocumentBuyerLegalOrganisation((string) ($buyer['siren'] ?? substr((string) $buyer['siret'], 0, 9)), '0002', (string) ($buyer['name'] ?? ''));
        }
        if (!empty($buyer['vat_number'])) {
            $document->addDocumentBuyerVATRegistrationNumber((string) $buyer['vat_number']);
        }
        if (!empty($buyer['email'])) {
            $document->setDocumentBuyerCommunication('EM', (string) $buyer['email']);
        }
        if (!empty($buyer['delivery'])) {
            $document->setDocumentShipTo($buyer['delivery']['name'] ?? null);
            $document->setDocumentShipToAddress(...self::address($buyer['delivery']));
        }
        if ($soldAt = $invoice->getSoldAt()) {
            $document->setDocumentSupplyChainEvent($soldAt);
        }

        // The lines.
        foreach ($invoice->getLines() as $line) {
            $document->addNewPosition((string) $line['id']);
            $document->setDocumentPositionProductDetails((string) $line['label'], null, $line['reference'] ?? null);
            $document->setDocumentPositionNetPrice($money($line['unit_price']));
            $document->setDocumentPositionQuantity((float) $line['quantity'], 'H87');
            $document->addDocumentPositionTax((string) $line['category'], 'VAT', (float) $line['rate'], null, $exemption['reason'] ?? null, $exemption['code'] ?? null);
            $document->setDocumentPositionLineSummation($money($line['total']));
        }

        // The order's charges and discount, by rate.
        foreach ($totals['charges'] ?? [] as $charge) {
            $document->addDocumentAllowanceCharge($money($charge['total']), true, (string) $charge['category'], 'VAT', (float) $charge['rate'], null, null, null, null, null, null, (string) $charge['kind']);
        }
        foreach ($totals['allowances'] ?? [] as $allowance) {
            $document->addDocumentAllowanceCharge($money($allowance['total']), false, (string) $allowance['category'], 'VAT', (float) $allowance['rate'], null, null, null, null, null, null, (string) $allowance['kind']);
        }

        // The VAT, by rate.
        foreach ($totals['vat_breakdown'] ?? [] as $vat) {
            $document->addDocumentTax((string) $vat['category'], 'VAT', $money($vat['base']), $money($vat['vat']), (float) $vat['rate'], 'S' === $vat['category'] || 'Z' === $vat['category'] ? null : ($exemption['reason'] ?? null), 'S' === $vat['category'] || 'Z' === $vat['category'] ? null : ($exemption['code'] ?? null));
        }

        // How it is paid.
        $paid = (int) ($totals['paid'] ?? 0);
        $due = (int) ($totals['due'] ?? 0);
        if (!$invoice->isCreditNote()) {
            $document->addDocumentPaymentTerm($due > 0 ? 'Payable le '.($invoice->getDueAt() ?? $invoice->getIssuedAt())->format('d/m/Y') : 'Payée', $due > 0 ? ($invoice->getDueAt() ?? $invoice->getIssuedAt()) : null);
        }

        $document->setDocumentSummation(
            $money($totals['total']),
            $invoice->isCreditNote() ? $money($totals['total']) : $money($due),
            $money($totals['lines']),
            $money($totals['charges_total'] ?? 0),
            $money($totals['allowances_total'] ?? 0),
            $money($totals['total_without_vat']),
            $money($totals['vat']),
            null,
            $invoice->isCreditNote() ? 0.0 : $money($paid),
        );

        return $document;
    }

    /**
     * An address as zugferd takes it: three lines, postcode, city, country.
     *
     * @param array<string, mixed> $party
     *
     * @return array{?string, ?string, ?string, ?string, ?string, ?string}
     */
    private static function address(array $party): array
    {
        $street = array_values((array) ($party['street'] ?? []));

        return [$street[0] ?? null, $street[1] ?? null, $street[2] ?? null, ($party['postcode'] ?? '') ?: null, ($party['city'] ?? '') ?: null, strtoupper((string) ($party['country'] ?? '')) ?: null];
    }
}
