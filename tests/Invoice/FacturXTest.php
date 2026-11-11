<?php

namespace Tests\Base\Marketplace\Invoice;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Invoice\FacturX;
use horstoeko\zugferd\ZugferdSettings;
use PHPUnit\Framework\TestCase;

/**
 * Each line of the Factur-X is self-consistent: its quantity times its net
 * price, rounded to the cent, is its net amount - the amount billed, never
 * changed to fit. An hours line billed from the minutes (4h25 at 90.00 an
 * hour: 397.50) carries its hours to the decimals that give that amount
 * back (4.4167, not 4.42, which makes 397.80); a round quantity stays at two.
 */
final class FacturXTest extends TestCase
{
    protected function setUp(): void
    {
        if (!FacturX::available()) {
            self::markTestSkipped('Needs horstoeko/zugferd.');
        }
    }

    /**
     * An invoice of these lines at 20 %, as an issuer writes them: amounts in cents.
     *
     * @param list<array<string, mixed>> $lines
     */
    private static function invoice(array $lines): Invoice
    {
        $base = array_sum(array_column($lines, 'total'));
        $vat = (int) round($base * 0.2);
        foreach ($lines as $i => $line) {
            $lines[$i] += ['id' => (string) ($i + 1), 'reference' => null, 'gross_unit_price' => $line['unit_price'], 'discount' => 0, 'rate' => 20.0, 'category' => 'S'];
        }
        $at = new \DateTimeImmutable('2026-11-30');

        return new Invoice(Invoice::TYPE_INVOICE, 'F', 2026, 7, 'F-2026-000007', $at, null, 'EUR',
            ['name' => 'Atelier Exemple SAS', 'street' => ['1 rue de l\'Exemple'], 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR', 'siren' => '123456789', 'vat_number' => 'FR32123456789', 'email' => 'factures@example.org'],
            ['name' => 'Client Exemple SARL', 'company' => true, 'business' => true, 'email' => 'compta@example.com', 'address' => ['street' => ['2 avenue Exemple'], 'postcode' => '69001', 'city' => 'Lyon', 'country' => 'FR'], 'siren' => '552100554'],
            $lines,
            ['lines' => $base, 'charges' => [], 'allowances' => [], 'charges_total' => 0, 'allowances_total' => 0, 'vat_breakdown' => [['rate' => 20.0, 'category' => 'S', 'base' => $base, 'vat' => $vat]], 'total_without_vat' => $base, 'vat' => $vat, 'total' => $base + $vat, 'paid' => 0, 'due' => $base + $vat],
            [], $at, $at->modify('+30 days'),
        );
    }

    /** An hours line, as omnibase/forge writes one: the amount from the minutes, the hours to four decimals. */
    private static function hours(int $minutes, int $hourlyRate): array
    {
        return ['label' => 'Temps passé', 'quantity' => round($minutes / 60, 4), 'unit' => 'HUR', 'minutes' => $minutes, 'unit_price' => $hourlyRate, 'total' => (int) round($minutes * $hourlyRate / 60)];
    }

    /**
     * Each line's billed quantity, its unit, its net price and its net amount, as the XML carries them.
     *
     * @return list<array{quantity: string, unit: string, price: string, total: string}>
     */
    private static function lines(string $xml): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $lines = [];
        foreach ($xpath->query('//ram:IncludedSupplyChainTradeLineItem') as $item) {
            $quantity = $xpath->query('.//ram:BilledQuantity', $item)->item(0);
            $lines[] = [
                'quantity' => $quantity->textContent,
                'unit' => $quantity->getAttribute('unitCode'),
                'price' => $xpath->query('.//ram:NetPriceProductTradePrice/ram:ChargeAmount', $item)->item(0)->textContent,
                'total' => $xpath->query('.//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $item)->item(0)->textContent,
            ];
        }

        return $lines;
    }

    private static function assertSelfConsistent(array $line): void
    {
        self::assertSame($line['total'], number_format(round((float) $line['quantity'] * (float) $line['price'], 2), 2, '.', ''), sprintf('%s x %s gives the line\'s amount', $line['quantity'], $line['price']));
    }

    public function testAnHoursLineCarriesTheHoursThatGiveItsAmountBack(): void
    {
        $facturX = new FacturX();
        $invoice = self::invoice([self::hours(265, 9000)]);
        $settings = [ZugferdSettings::getQuantityDecimals(), ZugferdSettings::getSpecialDecimalPlacesMaps()];

        $xml = $facturX->xml($invoice);

        [$line] = self::lines($xml);
        self::assertSame(['quantity' => '4.4167', 'unit' => 'HUR', 'price' => '90.00', 'total' => '397.50'], $line, '4h25 at 90.00: 397.50, as the PDF says');
        self::assertSelfConsistent($line);
        self::assertSame([], $facturX->validate($xml), 'still valid against EN 16931\'s schema');
        self::assertStringContainsString('<ram:LineTotalAmount>397.50</ram:LineTotalAmount>', $xml, 'the amount billed is unchanged');

        $attached = (string) FacturX::extract($facturX->attach($invoice, self::pdf()));
        self::assertSame([$line], self::lines($attached), 'the PDF carries the same XML');

        self::assertSame($settings, [ZugferdSettings::getQuantityDecimals(), ZugferdSettings::getSpecialDecimalPlacesMaps()], 'the library\'s settings are left as they were');
    }

    public function testARoundQuantityStaysAtTwoDecimals(): void
    {
        $facturX = new FacturX();

        $xml = $facturX->xml(self::invoice([self::hours(300, 9000), ['label' => 'Vase', 'quantity' => 2, 'unit_price' => 1900, 'total' => 3800]]));

        [$hours, $goods] = self::lines($xml);
        self::assertSame(['quantity' => '5.00', 'unit' => 'HUR', 'price' => '90.00', 'total' => '450.00'], $hours);
        self::assertSame(['quantity' => '2.00', 'unit' => 'H87', 'price' => '19.00', 'total' => '38.00'], $goods, 'a piece, as before');
        self::assertSelfConsistent($hours);
        self::assertSelfConsistent($goods);
        self::assertSame([], $facturX->validate($xml));
    }

    public function testAUnitPriceInFractionsOfACentKeepsItsDecimals(): void
    {
        // 3 for 10.00: 3.33 each would make 9.99.
        $xml = (new FacturX())->xml(self::invoice([['label' => 'Bol', 'quantity' => 3, 'unit_price' => 1000 / 3, 'total' => 1000]]));

        [$line] = self::lines($xml);
        self::assertSame(['quantity' => '3.00', 'unit' => 'H87', 'price' => '3.3333', 'total' => '10.00'], $line);
        self::assertSelfConsistent($line);
    }

    /** A one-page PDF to attach the XML to. */
    private static function pdf(): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs dompdf/dompdf.');
        }
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml('<p>F-2026-000007</p>');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
