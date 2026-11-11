<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Model\InvoiceSource;
use Base\Marketplace\Service\Invoices;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * An invoice of services rendered over a period - omnibase/forge's month of
 * hours - says so: its lines give their period, the invoice's billing period
 * follows (BT-73/BT-74 in the Factur-X, each line's BT-134/BT-135), and the
 * PDF prints it, its date read as the services' completion rather than "Date
 * de la vente". An invoice of goods reads as before.
 */
final class BillingPeriodTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private Invoices $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = self::getContainer()->get(Invoices::class);
    }

    /** @param list<?array{start: string, end: string}> $periods one line per period (null: a line without one) */
    private function issue(array $periods): Invoice
    {
        $lines = [];
        foreach ($periods as $i => $period) {
            $lines[] = ['id' => (string) ($i + 1), 'label' => 'Temps passé', 'reference' => null, 'quantity' => 2.0, 'unit' => 'HUR', 'minutes' => 120,
                'unit_price' => 9000, 'gross_unit_price' => 9000, 'discount' => 0, 'rate' => 20.0, 'category' => 'S', 'total' => 18000, 'vat' => 3600, 'total_with_vat' => 21600]
                + (null !== $period ? ['period' => $period] : []);
        }
        $base = 18000 * \count($lines);

        return $this->invoices->issueFor(new InvoiceSource(
            buyer: self::getContainer()->get(InvoiceComposer::class)->buyerOf($this->user('client')),
            lines: $lines,
            totals: ['lines' => $base, 'charges' => [], 'allowances' => [], 'charges_total' => 0, 'allowances_total' => 0,
                'vat_breakdown' => [['rate' => 20.0, 'category' => 'S', 'base' => $base, 'vat' => (int) round($base * 0.2)]],
                'total_without_vat' => $base, 'vat' => (int) round($base * 0.2), 'total' => $base + (int) round($base * 0.2), 'exemption' => null],
            soldAt: new \DateTimeImmutable('2026-10-31'),
            store: $this->store(),
            operation: 'services',
        ), new \DateTimeImmutable('2026-11-02'));
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        return $xpath;
    }

    public function testTheLinesPeriodsMakeTheInvoices(): void
    {
        $invoice = $this->issue([['start' => '2026-10-01', 'end' => '2026-10-31'], ['start' => '2026-10-15', 'end' => '2026-10-20']]);
        // As the database gives it back.
        $this->entityManager->clear();
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());

        $period = $invoice->getBillingPeriod();
        self::assertSame(['2026-10-01', '2026-10-31'], [$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')]);
        self::assertTrue($invoice->isServices());

        $html = $this->invoices->html($invoice);
        self::assertStringContainsString('Date de réalisation des prestations : 31/10/2026', $html);
        self::assertStringContainsString('Période de facturation : du 01/10/2026 au 31/10/2026', $html);
        self::assertStringNotContainsString('Date de la vente', $html);

        if (!FacturX::available()) {
            return;
        }
        $xml = $this->invoices->xml($invoice);
        self::assertSame([], self::getContainer()->get(FacturX::class)->validate($xml));
        $xpath = self::xpath($xml);
        $header = '//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod';
        self::assertSame('20261001', $xpath->evaluate("string($header/ram:StartDateTime/udt:DateTimeString)"), 'BT-73');
        self::assertSame('20261031', $xpath->evaluate("string($header/ram:EndDateTime/udt:DateTimeString)"), 'BT-74');
        $lines = '//ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod';
        self::assertSame(['20261001', '20261015'], array_map(fn (\DOMNode $n) => $n->textContent, iterator_to_array($xpath->query("$lines/ram:StartDateTime/udt:DateTimeString"))), 'BT-134 of each line');
        self::assertSame(['20261031', '20261020'], array_map(fn (\DOMNode $n) => $n->textContent, iterator_to_array($xpath->query("$lines/ram:EndDateTime/udt:DateTimeString"))), 'BT-135');
    }

    public function testAnInvoiceWithoutPeriodsReadsAsBefore(): void
    {
        $invoice = $this->issue([null]);

        self::assertNull($invoice->getBillingPeriod());
        self::assertFalse($invoice->isServices());
        $html = $this->invoices->html($invoice);
        self::assertStringContainsString('Date de la vente : 31/10/2026', $html);
        self::assertStringNotContainsString('Période de facturation', $html);

        if (FacturX::available()) {
            self::assertStringNotContainsString('BillingSpecifiedPeriod', $this->invoices->xml($invoice));
        }
    }

    public function testSomeLinesWithoutAPeriodKeepTheSalesDate(): void
    {
        // A month of hours and a licence: the period is the hours', the date still a sale's.
        $invoice = $this->issue([['start' => '2026-10-01', 'end' => '2026-10-31'], null]);

        self::assertSame('2026-10-01', $invoice->getBillingPeriod()['start']->format('Y-m-d'));
        self::assertFalse($invoice->isServices());
        self::assertStringContainsString('Date de la vente : 31/10/2026', $this->invoices->html($invoice));
    }

    public function testAPeriodThatDoesNotReadIsNone(): void
    {
        self::assertNull(Invoice::period(['period' => ['start' => '2026-10-31', 'end' => '2026-10-01']]), 'an end before its start');
        self::assertNull(Invoice::period(['period' => ['start' => 'October']]));
        self::assertNull(Invoice::period([]));
        self::assertSame('2026-10-05', Invoice::period(['period' => ['start' => '2026-10-05']])['end']->format('Y-m-d'), 'a single day');
    }
}
