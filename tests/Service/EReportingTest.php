<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Invoice\InvoiceNumbering;
use Base\Marketplace\Invoice\Transmission\EReporting;
use Base\Marketplace\Invoice\Transmission\EReportWriterInterface;
use Base\Marketplace\Invoice\Transmission\Entity\EReport;
use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Base\Marketplace\Http\BackOfficeFormTrait;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The e-reporting (CGI art. 290, 290 A): the periods and deadlines of each
 * VAT regime as impots.gouv.fr's table gives them (August 2026); the sales to
 * private persons aggregated by day, category and rate, a credit note taken
 * off; a sale to a company abroad invoice by invoice; the payments of
 * services; kept COLLECTED without the application's file writer, submitted
 * to the platform (omnibill's afnor gateway, a MockHttpClient) with one.
 */
final class EReportingTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;
    use TransmissionSchemaTrait;
    use BackOfficeFormTrait;

    /** @var list<array{string, string, string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(AfnorGatewayFactory::class)) {
            self::markTestSkipped('Needs omnisense/omnibill and omnibill/afnor.');
        }
        parent::setUp();
        $this->createTransmissionSchema();
    }

    protected function tearDown(): void
    {
        $this->requests = [];
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> regime, kind, a day, its period's first and last days, its deadline */
    public static function periods(): iterable
    {
        yield 'monthly normal regime, first ten days' => ['normal_monthly', 'transactions', '2026-11-03', '2026-11-01', '2026-11-10', '2026-11-20'];
        yield 'monthly normal regime, second ten days' => ['normal_monthly', 'transactions', '2026-11-15', '2026-11-11', '2026-11-20', '2026-11-30'];
        yield 'monthly normal regime, second ten days of February' => ['normal_monthly', 'transactions', '2027-02-15', '2027-02-11', '2027-02-20', '2027-02-28'];
        yield 'monthly normal regime, the rest of the month' => ['normal_monthly', 'transactions', '2026-12-31', '2026-12-21', '2026-12-31', '2027-01-10'];
        yield 'monthly normal regime, payments by month' => ['normal_monthly', 'payments', '2026-11-15', '2026-11-01', '2026-11-30', '2026-12-10'];
        yield 'quarterly option, by month' => ['normal_quarterly', 'transactions', '2026-11-15', '2026-11-01', '2026-11-30', '2026-12-10'];
        yield 'simplified regime, by month' => ['simplified', 'transactions', '2026-11-15', '2026-11-01', '2026-11-30', '2026-12-25'];
        yield 'simplified regime, payments' => ['simplified', 'payments', '2026-11-15', '2026-11-01', '2026-11-30', '2026-12-25'];
        yield 'franchise, two calendar months' => ['franchise', 'transactions', '2026-12-05', '2026-11-01', '2026-12-31', '2027-01-25'];
        yield 'franchise, January and February' => ['franchise', 'payments', '2027-01-20', '2027-01-01', '2027-02-28', '2027-03-25'];
    }

    /**
     * @dataProvider periods
     */
    #[DataProvider('periods')]
    public function testEachRegimeHasItsPeriodsAndDeadlines(string $regime, string $kind, string $day, string $from, string $to, string $due): void
    {
        [$start, $end, $deadline] = EReporting::period($regime, $kind, new \DateTimeImmutable($day));

        self::assertSame([$from, $to, $due], [$start->format('Y-m-d'), $end->format('Y-m-d'), $deadline->format('Y-m-d')]);
    }

    private function reporting(?EReportWriterInterface $writer = null, string $operation = 'goods'): EReporting
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/token')) {
                return new MockResponse('{"access_token":"tok","expires_in":3600}');
            }
            $this->requests[] = [$method, $url, \is_string($options['body'] ?? null) ? $options['body'] : ''];

            return new MockResponse('{"flowId":"RPT-1"}', ['http_code' => 202]);
        });
        $registry = new Registry([new AfnorGatewayFactory($http)], ['platform' => ['factory' => 'afnor', 'options' => [
            'flow_url' => 'https://api.flow.pa.example/flow-service', 'token_url' => 'https://auth.pa.example/token', 'client_id' => 'shop', 'client_secret' => 'secret',
        ]]]);
        $c = self::getContainer();
        $transmission = new InvoiceTransmission($this->entityManager, $c->get('translator'), $registry, null, null, 'platform');

        return new EReporting($this->entityManager, $c->get(InvoiceComposer::class), $transmission, $registry, $writer, 'normal_monthly', $operation, false, 'platform', ['siren' => '123456789']);
    }

    private function order(\DateTimeImmutable $paidAt, int $price = 1000): Order
    {
        $order = new Order($this->store());
        $order->setCustomer($this->user('buyer'));
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        $item = new OrderItem($this->goods('bol', $price), 1);
        $order->addItem($item);
        $item->setVatCharge((int) round($item->getSalePrice() * 0.2));
        $this->entityManager->persist($item);
        $transaction = new Transaction();
        $transaction->setTotalAmount($order->getNetPrice());
        $transaction->setCurrencyCode('EUR');
        $order->addTransaction($transaction);
        $transaction->markAsPaid();
        $order->markAsPaidAt(\DateTime::createFromImmutable($paidAt));
        $order->markAsConfirmed();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $order;
    }

    /** An invoice of that order to a company abroad. */
    private function invoiceAbroad(Order $order, \DateTimeImmutable $at): Invoice
    {
        $c = self::getContainer();
        $composer = $c->get(InvoiceComposer::class);
        $buyer = ['name' => 'Beispiel GmbH', 'company' => true, 'business' => true, 'vat_number' => 'DE123456789', 'address' => ['street' => ['Hauptstraße 1'], 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'DE']] + $composer->buyer($order);
        ['lines' => $lines, 'totals' => $totals] = $composer->amounts($order);

        return $c->get(InvoiceNumbering::class)->issue('F', $at, fn (int $sequence, string $number, int $year) => new Invoice(
            Invoice::TYPE_INVOICE, 'F', $year, $sequence, $number, $at, $order, 'EUR', $composer->seller($order), $buyer, $lines, $totals, [], $at, $at,
        ));
    }

    public function testTheSalesToPrivatePersonsAndAbroadAreCollectedThenKeptWithoutAWriter(): void
    {
        // A month of its own, far from the other tests' orders: November 2031, its second ten days.
        $day = new \DateTimeImmutable('2031-11-12 10:00:00');
        $this->order($day, 1000);
        $this->order($day->modify('+2 hours'), 2500);
        $abroad = $this->invoiceAbroad($this->order($day->modify('+1 day'), 4000), $day->modify('+1 day'));
        $this->order(new \DateTimeImmutable('2031-11-25 10:00:00'), 9900);   // the next period's

        $reporting = $this->reporting();
        $report = $reporting->collect(EReport::TRANSACTIONS, $day);

        self::assertSame(['2031-11-11', '2031-11-20', '2031-11-30'], [$report->getStartsOn()->format('Y-m-d'), $report->getEndsOn()->format('Y-m-d'), $report->getDueOn()->format('Y-m-d')]);
        $data = $report->getData();
        self::assertSame(['123456789', 'normal_monthly', ['from' => '2031-11-11', 'to' => '2031-11-20']], [$data['siren'], $data['regime'], $data['period']]);
        self::assertSame([['date' => '2031-11-12', 'category' => 'goods', 'rates' => [['rate' => 20.0, 'base' => '35.00', 'vat' => '7.00']]]], $data['b2c'], 'the day\'s two sales to private persons, aggregated by category and rate');
        self::assertCount(1, $data['b2b_international']);
        $line = $data['b2b_international'][0];
        self::assertSame([$abroad->getNumber(), '380', 'LB', 'DE123456789', 'DE', '40.00', '8.00'], [$line['number'], $line['type'], $line['category'], $line['buyer']['identifier'], $line['buyer']['country'], $line['total_without_vat'], $line['vat']]);
        self::assertSame([['rate' => 20.0, 'category' => 'S', 'base' => '40.00', 'vat' => '8.00']], $line['rates']);

        // No writer: kept, never sent, and it says why.
        $reporting->send($report);
        self::assertSame(EReport::COLLECTED, $report->getState());
        self::assertStringContainsString('EReportWriterInterface', (string) $report->getError());
        self::assertSame([], $this->requests);

        // Collected again: the same period, its data afresh.
        self::assertSame($report->getId(), $reporting->collect(EReport::TRANSACTIONS, $day)->getId());

        // Its screen in the back office: the period, its deadline, why it waits, what was collected.
        $this->signInToTheBackOffice();
        $index = $this->open('/admin/ereports');
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString('EReportWriterInterface', html_entity_decode((string) $index->getContent()));
        $detail = $this->open('/admin/ereports/'.$report->getId());
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString($abroad->getNumber(), html_entity_decode((string) $detail->getContent()));
    }

    public function testWithAWriterThePeriodIsSubmittedOnceAndThePaymentsOfServicesAreReported(): void
    {
        $day = new \DateTimeImmutable('2032-03-04 10:00:00');
        $this->order($day, 1000);
        $writer = new class implements EReportWriterInterface {
            public array $written = [];

            public function write(EReport $report): ?array
            {
                $this->written[] = $report->getData();

                return ['content' => '<written-by-the-application/>', 'filename' => 'report.xml', 'rule' => 'B2C'];
            }
        };
        $reporting = $this->reporting($writer, 'services');

        $payments = $reporting->collect(EReport::PAYMENTS, $day);
        self::assertSame(['2032-03-01', '2032-03-31', '2032-04-10'], [$payments->getStartsOn()->format('Y-m-d'), $payments->getEndsOn()->format('Y-m-d'), $payments->getDueOn()->format('Y-m-d')]);
        self::assertSame([['date' => '2032-03-04', 'rates' => [['rate' => 20.0, 'amount' => '12.00']]]], $payments->getData()['b2c'], 'the day\'s payments, by rate, VAT included');

        $reporting->send($payments);
        self::assertSame([EReport::SENT, 'platform', 'RPT-1'], [$payments->getState(), $payments->getGateway(), $payments->getReference()]);
        self::assertCount(1, $this->requests);
        [$method, $url, $body] = $this->requests[0];
        self::assertSame(['POST', 'https://api.flow.pa.example/flow-service/v1/flows'], [$method, $url]);
        self::assertStringContainsString('"flowSyntax":"FRR"', $body);
        self::assertStringContainsString('"processingRule":"B2C"', $body);
        self::assertStringContainsString('<written-by-the-application/>', $body, 'the application\'s file, carried as it is');

        $reporting->send($payments);
        self::assertCount(1, $this->requests, 'sent once');
        try {
            $payments->collected([]);
            self::fail('A period sent is not collected again.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
    }
}
