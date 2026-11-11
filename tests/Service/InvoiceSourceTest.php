<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Model\InvoiceSource;
use Base\Marketplace\Service\Invoices;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * An invoice made without an order - omnibase/forge's hours, a subscription -
 * issued through the shop's own entry point (Invoices::issueFor()): the same
 * series without a gap as the orders' invoices, the seller and the mentions
 * written by marketplace from its settings, the caller giving only its buyer,
 * its lines and its totals. compose() shows what it would issue.
 */
final class InvoiceSourceTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private Invoices $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = self::getContainer()->get(Invoices::class);
    }

    /** A business, read where it has its details: an application's own customer, not marketplace's. */
    private function client(): object
    {
        return new class {
            public function getCompanyName(): string
            {
                return 'Atelier Client SARL';
            }

            public function getSiren(): string
            {
                return '552100554';
            }

            public function getEmail(): string
            {
                return 'compta@client.example';
            }
        };
    }

    private function address(): object
    {
        return new class {
            public function getStreetAddress(): string
            {
                return '3 quai des Bateliers';
            }

            public function getZipCode(): string
            {
                return '67000';
            }

            public function getCity(): string
            {
                return 'Strasbourg';
            }

            public function getCountry(): string
            {
                return 'fr';
            }
        };
    }

    /** 4h25 at 90,00 € an hour before VAT, at 20 %: 397,50 + 79,50. */
    private function source(array $overrides = []): InvoiceSource
    {
        $buyer = self::getContainer()->get(InvoiceComposer::class)->buyerOf($this->client(), $this->address());
        $line = ['id' => '1', 'label' => 'Site — temps passé (4h25)', 'reference' => 'site', 'quantity' => 4.4167, 'unit' => 'HUR', 'minutes' => 265,
            'unit_price' => 9000, 'gross_unit_price' => 9000, 'discount' => 0, 'rate' => 20.0, 'category' => 'S', 'total' => 39750, 'vat' => 7950, 'total_with_vat' => 47700];
        $totals = ['lines' => 39750, 'charges' => [], 'allowances' => [], 'charges_total' => 0, 'allowances_total' => 0,
            'vat_breakdown' => [['rate' => 20.0, 'category' => 'S', 'base' => 39750, 'vat' => 7950]],
            'total_without_vat' => 39750, 'vat' => 7950, 'total' => 47700, 'exemption' => null];

        return new InvoiceSource(...$overrides + [
            'buyer' => $buyer,
            'lines' => [$line],
            'totals' => $totals,
            'soldAt' => new \DateTimeImmutable('2026-10-31'),
            'store' => $this->store()->setVatNumber('FR40123456789'),
            'operation' => 'services',
            'mentions' => ['Prestations réalisées du 01/10/2026 au 31/10/2026.'],
        ]);
    }

    private function paidOrder(): Order
    {
        $order = new Order($this->store());
        $order->setCustomer($this->user('buyer'));
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        $item = new OrderItem($this->goods('vase', 1900), 1);
        $order->addItem($item);
        $item->setVatCharge((int) round($item->getSalePrice() * 0.2));
        $this->entityManager->persist($item);
        $transaction = new Transaction();
        $transaction->setTotalAmount($order->getNetPrice());
        $transaction->setCurrencyCode('EUR');
        $order->addTransaction($transaction);
        $order->markAsPaidAt();
        $order->markAsConfirmed();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $order;
    }

    public function testAnInvoiceWithoutAnOrderIsIssuedInTheShopsSeries(): void
    {
        $before = $this->invoices->issue($this->paidOrder());
        $told = null;
        $at = new \DateTimeImmutable('2026-11-02 10:00');
        $invoice = $this->invoices->issueFor($this->source(), $at, function (Invoice $issued) use (&$told): void {
            $told = [$issued->getNumber(), $issued->getId()];
        });

        self::assertSame($before->getSequence() + 1, $invoice->getSequence(), 'the next number of the same series');
        self::assertSame($before->getSeries(), $invoice->getSeries());
        self::assertSame([$invoice->getNumber(), null], $told, 'told inside the transaction, before it is stored');
        self::assertNotNull($invoice->getId());
        self::assertNull($invoice->getOrder());
        self::assertSame('2026-10-31', $invoice->getSoldAt()?->format('Y-m-d'));
        self::assertSame('2026-12-02', $invoice->getDueAt()?->format('Y-m-d'), 'marketplace.invoice.payment_days after the issue');
        self::assertFalse($invoice->isPaid());

        $seller = $invoice->getSeller();
        self::assertSame(['Glitch Art Studio', '123456789', 'FR40123456789'], [$seller['name'], $seller['siren'], $seller['vat_number']], 'marketplace.invoice.seller, the store\'s VAT number over it');
        self::assertSame((string) $this->store(), $seller['store']);

        $buyer = $invoice->getBuyer();
        self::assertSame(['Atelier Client SARL', true, true, '552100554', 'compta@client.example'], [$buyer['name'], $buyer['company'], $buyer['business'], $buyer['siren'], $buyer['email']]);
        self::assertSame(['street' => ['3 quai des Bateliers'], 'postcode' => '67000', 'city' => 'Strasbourg', 'country' => 'FR'], $buyer['address']);
        self::assertNull($buyer['delivery']);
        self::assertSame(0, $invoice->getTotals()['paid']);
        self::assertSame(47700, $invoice->getTotals()['due']);

        $mentions = $invoice->getMentions();
        self::assertSame('Nature des opérations : prestations de services.', $mentions[0], 'the nature of the operations given');
        self::assertSame('Prestations réalisées du 01/10/2026 au 31/10/2026.', $mentions[1], 'its own mentions after it');
        self::assertContains('À régler au plus tard le 02/12/2026.', $mentions);
        self::assertContains('Escompte pour paiement anticipé : néant.', $mentions);
        self::assertNotEmpty(array_filter($mentions, fn ($m) => str_starts_with($m, 'Pénalités de retard')), 'to a business: the late penalties');
        self::assertNotContains('Nature des opérations : livraisons de biens.', $mentions);
    }

    public function testComposeShowsWhatIssueForFreezes(): void
    {
        $at = new \DateTimeImmutable('2026-11-02 10:00');
        $preview = $this->invoices->compose($this->source(), $at);
        $invoice = $this->invoices->issueFor($this->source(), $at);

        self::assertSame($preview['mentions'], $invoice->getMentions());
        self::assertEquals($preview['seller'], $invoice->getSeller());
        self::assertSame($preview['lines'], $invoice->getLines());
        self::assertSame($preview['totals'], $invoice->getTotals());
        self::assertEquals($preview['due_at'], $invoice->getDueAt());
    }

    public function testAnExemptPrivatePersonPaidAlready(): void
    {
        // A private person, the seller's franchise (CGI art. 293 B), paid when invoiced: "acquittée", no penalties.
        $exemption = InvoiceComposer::exemption('TVA non applicable, art. 293 B du CGI');
        $buyer = self::getContainer()->get(InvoiceComposer::class)->buyerOf(new class {
            public function getFirstname(): string
            {
                return 'Camille';
            }

            public function getLastname(): string
            {
                return 'Durand';
            }

            public function getEmail(): string
            {
                return 'camille@example.org';
            }
        });
        $line = ['id' => '1', 'label' => 'Abonnement', 'quantity' => 1, 'unit_price' => 1200, 'rate' => 0.0, 'category' => 'E', 'total' => 1200, 'vat' => 0, 'total_with_vat' => 1200];
        $source = $this->source([
            'buyer' => $buyer,
            'lines' => [$line],
            'totals' => ['lines' => 1200, 'vat_breakdown' => [['rate' => 0.0, 'category' => 'E', 'base' => 1200, 'vat' => 0]], 'total_without_vat' => 1200, 'vat' => 0, 'total' => 1200, 'paid' => 1200, 'exemption' => $exemption],
            'paidAt' => new \DateTimeImmutable('2026-11-01'),
            'operation' => null,
            'mentions' => [],
        ]);

        $invoice = $this->invoices->issueFor($source, new \DateTimeImmutable('2026-11-02'));

        self::assertSame(['Camille Durand', false], [$invoice->getBuyer()['name'], $invoice->getBuyer()['business']]);
        self::assertTrue($invoice->isPaid());
        self::assertSame(0, $invoice->getTotals()['due']);
        $mentions = $invoice->getMentions();
        self::assertSame('TVA non applicable, art. 293 B du CGI', $mentions[0], 'the exemption, from the totals');
        self::assertSame('Nature des opérations : livraisons de biens.', $mentions[1], 'no operation given: marketplace.invoice.operation');
        self::assertContains('Facture acquittée le 01/11/2026.', $mentions);
        self::assertEmpty(array_filter($mentions, fn ($m) => str_starts_with($m, 'Pénalités de retard') || str_starts_with($m, 'À régler')));
    }

    public function testNothingToInvoiceIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        $this->invoices->issueFor($this->source(['lines' => []]));
    }

    public function testItsFacturXIsValid(): void
    {
        if (!FacturX::available()) {
            self::markTestSkipped('Needs horstoeko/zugferd.');
        }
        $invoice = $this->invoices->issueFor($this->source(), new \DateTimeImmutable('2026-11-02'));
        $xml = $this->invoices->xml($invoice);

        self::assertSame([], self::getContainer()->get(FacturX::class)->validate($xml));
        self::assertStringContainsString('unitCode="HUR">4.4167<', $xml);
        self::assertStringNotContainsString('SellerOrderReferencedDocument', $xml, 'no order to refer to');
    }
}
