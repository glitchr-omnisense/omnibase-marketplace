<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Enum\InvoiceRoute;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Invoice\InvoiceNumbering;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Base\Marketplace\Invoice\Transmission\InvoicePaidListener;
use Base\Marketplace\Invoice\Transmission\InvoiceStatusCommand;
use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Base\Marketplace\Invoice\Transmission\LifecycleMessageInterface;
use Base\Marketplace\Invoice\Transmission\TransmissionException;
use Base\Marketplace\Service\Invoices;
use Omnibill\Action\ActionInterface;
use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Config;
use Omnibill\Email\EmailGatewayFactory;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\GatewayFactory;
use Omnibill\Model\Capabilities;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\Inbox;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\Received;
use Omnibill\Model\StatusChange;
use Omnibill\Registry;
use Omnibill\Request\Receive;
use Omnibill\Request\Request;
use Omnibill\Request\SetStatus;
use Omnibill\Request\Submit;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Email;
use Tests\Base\Marketplace\Http\BackOfficeFormTrait;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The shop's invoices issued through an approved platform: omnibill's real
 * afnor gateway (XP Z12-013) on a MockHttpClient that records each request
 * and answers as the standard's OpenAPI describes - no platform is called.
 *
 * An e-invoice (a company established in France) is addressed through the
 * platform's directory, deposited once, followed (deposited, rejected and
 * why, refused by its buyer), corrected by a credit note and a new invoice;
 * "paid" waits without a lifecycle message writer and goes with one, with
 * the amount received at each VAT rate; a sale to a private person goes by
 * e-mail instead. A deposit or a status whose answer is lost after it left
 * (omnibill's OutcomeUnknownException) is looked up, never sent twice; only
 * the lifecycle flows are read from the inbox. Skipped without omnibill.
 */
final class InvoicePlatformTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;
    use TransmissionSchemaTrait;
    use BackOfficeFormTrait;

    private const BUYER_SIREN = '552100554';
    private const WEBHOOK_KEY = 'c2lnbmluZy1rZXktb2YtdGhlLXdlYmhvb2stMzItYnl0ZXM=';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse|callable> answered in order, after the token's */
    private array $answers = [];

    private ?LifecycleMessageInterface $writer = null;

    /** The platform's flow of this test's invoice: a reference of its own, the database being shared by the suite. */
    private string $flowId;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(AfnorGatewayFactory::class) || !class_exists(EmailGatewayFactory::class)) {
            self::markTestSkipped('Needs omnisense/omnibill, omnibill/afnor and omnibill/email.');
        }
        parent::setUp();
        if (!FacturX::available() || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs horstoeko/zugferd and dompdf/dompdf.');
        }
        $this->createTransmissionSchema();
        $this->flowId = 'FLW-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // PHPUnit keeps every test case to the end of the run: the PDFs recorded go now.
        $this->requests = $this->answers = [];
        $this->writer = null;
        parent::tearDown();
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/token')) {
                return new MockResponse('{"access_token":"tok","token_type":"Bearer","expires_in":3600}');
            }
            $body = $options['body'] ?? '';
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => \is_string($body) ? $body : ''];
            $answer = array_shift($this->answers) ?? new MockResponse('{"errorCode":"UNEXPECTED"}', ['http_code' => 500]);

            return \is_callable($answer) ? $answer($method, $url, $options) : $answer;
        });
    }

    private function registry(?GatewayFactory $platform = null): Registry
    {
        $container = self::getContainer();

        return new Registry([
            $platform ?? new AfnorGatewayFactory($this->http()),
            new EmailGatewayFactory($container->get('mailer.mailer'), $container->get(\Omnibill\Email\StatusStoreInterface::class)),
        ], [
            'platform' => ['factory' => $platform ? 'inbox' : 'afnor', 'options' => [
                'flow_url' => 'https://api.flow.pa.example/flow-service',
                'directory_url' => 'https://api.directory.pa.example/directory-service',
                'token_url' => 'https://auth.pa.example/auth/token',
                'client_id' => 'shop',
                'client_secret' => 'secret',
                'webhook_key' => self::WEBHOOK_KEY,
            ]],
            'email' => ['factory' => 'email', 'options' => ['from' => 'factures@example.org']],
        ]);
    }

    private function transmission(?Registry $registry = null): InvoiceTransmission
    {
        $container = self::getContainer();

        return new InvoiceTransmission(
            $this->entityManager, $container->get('translator'), $registry ?? $this->registry(), $container->get('mailer.mailer'),
            $container->get(\Omnibill\Email\StatusStoreInterface::class), 'platform', $this->writer, null, 'email', 'goods',
        );
    }

    private function invoices(InvoiceTransmission $transmission): Invoices
    {
        $c = self::getContainer();

        return new Invoices(
            $this->entityManager, $c->get(InvoiceComposer::class), $c->get(InvoiceNumbering::class), $c->get(FacturX::class),
            $c->get('twig'), $c->get('router'), $c->get(UriSigner::class), $c->get('mailer.mailer'), $c->get('translator'),
            'F', 'A', 30, $transmission,
        );
    }

    private function order(bool $paid = true): Order
    {
        $order = new Order($this->store());
        $order->setCustomer($this->user('buyer'));
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        $item = new OrderItem($this->goods('vase', 1900), 2);
        $order->addItem($item);
        $item->setVatCharge((int) round($item->getSalePrice() * 0.2));
        $this->entityManager->persist($item);
        $transaction = new Transaction();
        $transaction->setTotalAmount($order->getNetPrice());
        $transaction->setCurrencyCode('EUR');
        $order->addTransaction($transaction);
        if ($paid) {
            $transaction->markAsPaid();
        }
        $order->markAsPaidAt();
        $order->markAsConfirmed();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * The order's invoice, its buyer the company given: issued as Invoices does, the
     * buyer being what the application's User would carry (getSiren(), getCompanyName()).
     *
     * @param array<string, mixed> $buyer
     */
    private function invoice(Invoices $invoices, array $buyer = [], bool $paid = true): Invoice
    {
        $c = self::getContainer();
        $order = $this->order($paid);
        $composer = $c->get(InvoiceComposer::class);
        $seller = $composer->seller($order);
        $buyer = $buyer + $composer->buyer($order);
        ['lines' => $lines, 'totals' => $totals] = $composer->amounts($order);
        if (!$paid) {
            $totals['paid'] = 0;
            $totals['due'] = $totals['total'];
        }
        $at = new \DateTimeImmutable();

        return $c->get(InvoiceNumbering::class)->issue('F', $at, function (int $sequence, string $number, int $year) use ($order, $at, $seller, $buyer, $lines, $totals, $paid) {
            $invoice = new Invoice(Invoice::TYPE_INVOICE, 'F', $year, $sequence, $number, $at, $order, 'EUR', $seller, $buyer, $lines, $totals, [], $at, $paid ? $at : $at->modify('+30 days'));

            return $paid ? $invoice->markPaid($at) : $invoice;
        });
    }

    private static function company(): array
    {
        return ['name' => 'Automobiles Exemple SA', 'company' => true, 'business' => true, 'siren' => self::BUYER_SIREN, 'siret' => null, 'vat_number' => 'FR12'.self::BUYER_SIREN];
    }

    private static function directory(): MockResponse
    {
        return new MockResponse(json_encode(['results' => [
            ['siren' => self::BUYER_SIREN, 'siret' => self::BUYER_SIREN.'00099', 'addressingIdentifier' => self::BUYER_SIREN.'_ACHATS', 'directoryLineStatus' => 'Enabled', 'platformType' => 'WK'],
            ['siren' => self::BUYER_SIREN, 'addressingIdentifier' => self::BUYER_SIREN, 'directoryLineStatus' => 'Enabled', 'platformType' => 'WK'],
        ]]));
    }

    private function flow(string $status, array $details = []): MockResponse
    {
        return new MockResponse(json_encode(['flowId' => $this->flowId, 'flowSyntax' => 'Factur-X', 'flowDirection' => 'Out', 'flowType' => 'CustomerInvoice', 'submittedAt' => '2026-11-11T09:00:00Z', 'updatedAt' => '2026-11-11T09:05:00Z', 'acknowledgement' => ['status' => $status, 'details' => $details]]));
    }

    /**
     * The platform's answer to POST /v1/flows/search: these flows.
     *
     * @param list<array<string, mixed>> $flows
     */
    private static function search(array $flows): MockResponse
    {
        return new MockResponse(json_encode(['limit' => 100, 'results' => $flows]));
    }

    /** The deposit of this test's invoice, as the platform's search lists it. */
    private function deposited(string $number): array
    {
        return ['flowId' => $this->flowId, 'trackingId' => $number, 'flowSyntax' => 'Factur-X', 'flowDirection' => 'Out', 'flowType' => 'CustomerInvoice', 'submittedAt' => '2026-11-11T09:00:00Z', 'updatedAt' => '2026-11-11T09:00:30Z', 'acknowledgement' => ['status' => 'Ok']];
    }

    /** @return list<string> "METHOD path" of each request recorded, after the hosts */
    private function calls(): array
    {
        return array_map(static fn (array $r) => $r['method'].' '.parse_url($r['url'], \PHP_URL_PATH), $this->requests);
    }

    /** @return array{info: array<string, mixed>, file: string} what a multipart POST /v1/flows carried */
    private static function multipart(string $body): array
    {
        preg_match('~name="flowInfo"\r\nContent-Type: application/json\r\n\r\n(.*?)\r\n--~s', $body, $info);
        preg_match('~name="file"; filename="[^"]*"\r\nContent-Type: [^\r]+\r\n\r\n(.*)\r\n--omnibill-[0-9a-f]+--\r\n$~s', $body, $file);

        return ['info' => json_decode($info[1] ?? '{}', true), 'file' => $file[1] ?? ''];
    }

    public function testAnEInvoiceIsAddressedThroughTheDirectoryDepositedOnceAndFollowed(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);
        self::assertSame(InvoiceRoute::DOMESTIC_B2B, $transmission->routeOf($invoice));
        self::assertSame('platform', $transmission->channel($invoice));
        self::assertTrue($invoices->isEInvoice($invoice), 'sent without the buyer\'s e-mail');

        $this->answers = [self::directory(), new MockResponse('{"flowId":"'.$this->flowId.'","submittedAt":"2026-11-11T09:00:00Z"}', ['http_code' => 202])];
        $invoices->send($invoice);

        [$lookup, $submit] = $this->requests;
        self::assertSame(['POST', 'https://api.directory.pa.example/directory-service/v1/directory-line/search'], [$lookup['method'], $lookup['url']]);
        self::assertSame(self::BUYER_SIREN, json_decode($lookup['body'], true)['filters']['siren']['value'], 'the directory asked by the buyer\'s SIREN');
        self::assertSame(['POST', 'https://api.flow.pa.example/flow-service/v1/flows'], [$submit['method'], $submit['url']]);
        ['info' => $info, 'file' => $file] = self::multipart($submit['body']);
        self::assertSame(['B2B', 'Factur-X', $invoice->getNumber()], [$info['processingRule'], $info['flowSyntax'], $info['trackingId']]);
        self::assertSame(hash('sha256', $file), $info['sha256']);
        $xml = (string) FacturX::extract($file);
        self::assertStringContainsString('schemeID="0225">'.self::BUYER_SIREN.'</ram:URIID>', $xml, 'the buyer\'s electronic address, the legal unit\'s line (BT-49)');
        self::assertStringContainsString('schemeID="0225">123456789</ram:URIID>', $xml, 'the seller\'s, its SIREN (BT-34)');
        self::assertStringContainsString('<ram:ID>B1</ram:ID>', $xml, 'the invoicing frame, goods (BT-23)');
        self::assertSame([], self::getContainer()->get(FacturX::class)->validate($xml), 'still valid against EN 16931\'s schema');
        self::assertCount(0, array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email), 'no e-mail: the platform carries it');

        $flow = $transmission->flowOf($invoice);
        self::assertSame(['platform', $this->flowId, 'B2B', self::BUYER_SIREN, hash('sha256', $file)], [$flow->getGateway(), $flow->getReference(), $flow->getRule(), $flow->getRecipientAddress(), $flow->getFileSha256()]);
        self::assertNull($flow->getStatus(), 'pending: the platform has not acknowledged it yet');

        // Deposited (200): the invoice is sent.
        $this->answers = [$this->flow('Ok')];
        $transmission->refresh($invoice);
        self::assertSame(['GET', 'https://api.flow.pa.example/flow-service/v1/flows/'.$this->flowId.'?docType=Metadata'], [$this->requests[2]['method'], $this->requests[2]['url']]);
        self::assertSame('deposited', $flow->getStatus());
        self::assertSame(Invoice::STATE_SENT, $invoice->getState());

        // Submitted once.
        try {
            $invoices->send($invoice);
            self::fail('A second deposit is refused.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString($invoice->getNumber(), $e->getMessage());
        }
        self::assertCount(3, $this->requests, 'nothing went the second time');

        // Its payment, confirmed by omnitrade: the invoice paid, "Encaissée" waiting for a CDAR writer - none here.
        $order = $invoice->getOrder();
        foreach ($order->getTransactions() as $t) {
            $t->markAsPaid();
        }
        (new InvoicePaidListener($invoices, $transmission))(new OrderPaidEvent($order));
        self::assertTrue($invoice->isPaid());
        self::assertCount(3, $this->requests, 'no lifecycle message without its writer');
        $pending = array_values($flow->pending());
        self::assertSame('paid', $pending[0]['status']);
        self::assertSame(InvoiceTransmission::decimal($invoice->getTotal(), 'EUR'), $pending[0]['amount']);
        self::assertStringContainsString('LifecycleMessageInterface', (string) $pending[0]['error']);
        self::assertStringContainsString('LifecycleMessageInterface', (string) $invoices->guidance($invoice), 'the back office says why it waits');

        // A writer installed: the catch-up sends it, as the file the application wrote (the bundle writes no CDAR).
        $this->writer = new class implements LifecycleMessageInterface {
            public array $asked = [];

            public function write(Invoice $invoice, string $status, \DateTimeImmutable $at, ?string $reason = null, ?string $amount = null, array $amountsByRate = [], ?string $recipientAddress = null): ?array
            {
                $this->asked[] = [$status, $amount, $amountsByRate, $recipientAddress];

                return ['content' => '<written-by-the-application/>', 'filename' => 'status-'.$invoice->getNumber().'.xml'];
            }
        };
        $transmission = $this->transmission();
        $this->answers = [new MockResponse('{"flowId":"FLW-0002"}', ['http_code' => 202])];
        $tester = new CommandTester(new InvoiceStatusCommand($transmission, $this->entityManager));
        $tester->execute(['numbers' => ['none'], '--no-receive' => true]);
        self::assertStringContainsString('Statuses sent again: 1', $tester->getDisplay());
        ['info' => $info, 'file' => $file] = self::multipart($this->requests[3]['body']);
        self::assertSame(['CDAR', '<written-by-the-application/>'], [$info['flowSyntax'], $file]);
        self::assertSame(['paid', InvoiceTransmission::decimal($invoice->getTotal(), 'EUR'), [['rate' => 20.0, 'amount' => InvoiceTransmission::decimal($invoice->getTotal(), 'EUR')]], self::BUYER_SIREN], $this->writer->asked[0]);
        $this->entityManager->refresh($flow);
        self::assertSame([], $flow->pending());
        self::assertSame('paid', $flow->getStatus());
    }

    public function testARejectedInvoiceSaysWhyAndIsCorrectedByACreditNoteAndANewInvoice(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company());
        $this->answers = [self::directory(), new MockResponse('{"flowId":"'.$this->flowId.'"}', ['http_code' => 202]), $this->flow('Error', [['reasonCode' => 'REJ_SEMAN', 'reasonMessage' => 'Adresse électronique du destinataire inconnue', 'item' => 'BT-49']])];
        $invoices->send($invoice);
        self::assertCount(2, $this->requests, 'paid with the order, but "paid" waits: no writer');
        $transmission->refresh($invoice);

        $flow = $transmission->flowOf($invoice);
        self::assertSame(['rejected', 213], [$flow->getStatus(), $flow->getHistory()[0]['code']]);
        self::assertSame('REJ_SEMAN: Adresse électronique du destinataire inconnue (BT-49)', $flow->getReason());
        self::assertNull($invoice->getSentAt(), 'a rejected invoice did not reach its buyer');
        self::assertStringContainsString('Adresse électronique du destinataire inconnue', (string) $invoices->lifecycle($invoice));
        self::assertStringContainsString('Adresse électronique du destinataire inconnue', (string) $invoices->guidance($invoice), 'what to do about it, and why');
        self::assertSame('rejected', $invoices->lifecycleStatus($invoice));

        // The back office says it, on the invoice's screen and on its order's.
        $this->signInToTheBackOffice();
        foreach (['/admin/invoices/'.$invoice->getId(), '/admin/orders/'.$invoice->getOrder()->getId()] as $page) {
            $response = $this->open($page);
            self::assertSame(200, $response->getStatusCode(), $page);
            self::assertStringContainsString('Adresse électronique du destinataire inconnue', html_entity_decode((string) $response->getContent()), $page.': the platform\'s reason');
        }
        self::assertStringContainsString('/reissue', (string) $this->open('/admin/invoices/'.$invoice->getId())->getContent(), 'its correction offered');
        // The requests cleared the entity manager: the invoice read again.
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());

        ['credit_note' => $creditNote, 'invoice' => $new] = $invoices->reissue($invoice, $invoices->lifecycle($invoice));
        self::assertTrue($invoice->isCancelled());
        self::assertSame($invoice, $creditNote->getCredited());
        self::assertSame($invoice->getSequence() + 1, $new->getSequence(), 'the next number of the series: the sequence holds');
        self::assertNull($invoices->guidance($invoice), 'corrected: nothing left to do on it');
        self::assertNull($transmission->flowOf($new), 'the new invoice is not sent by itself');
    }

    public function testWithoutTheBuyersSirenNothingLeavesAndItSaysWhatToCorrect(): void
    {
        $invoices = $this->invoices($this->transmission());
        $invoice = $this->invoice($invoices, ['siren' => null, 'siret' => null, 'vat_number' => null, 'company' => true, 'business' => true, 'name' => 'Une Société']);

        try {
            $invoices->send($invoice);
            self::fail('No SIREN: refused.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString('SIREN', $e->getMessage());
        }
        self::assertSame([], $this->requests, 'nothing asked, nothing sent');
    }

    public function testASaleToAPrivatePersonIsNoEInvoiceItGoesByEmail(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices);
        self::assertSame(InvoiceRoute::B2C, $transmission->routeOf($invoice));
        self::assertSame('email', $transmission->channel($invoice));
        self::assertSame([], $transmission->addressing($invoice));

        $invoices->send($invoice);

        self::assertSame([], $this->requests, 'the platform is not asked');
        self::assertCount(1, array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), $invoice->getNumber())));
        self::assertSame(['email', 'sent'], [$transmission->flowOf($invoice)->getGateway(), $transmission->flowOf($invoice)->getStatus()]);
    }

    public function testTheWebhookIsCheckedAndUpdatesTheInvoice(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);
        $this->answers = [self::directory(), new MockResponse('{"flowId":"'.$this->flowId.'"}', ['http_code' => 202])];
        $invoices->send($invoice);

        $body = json_encode(['flowId' => $this->flowId, 'flowSyntax' => 'Factur-X', 'flowDirection' => 'Out', 'updatedAt' => '2026-11-11T10:00:00Z', 'acknowledgement' => ['status' => 'Ok']]);
        $timestamp = (string) time();
        $signature = base64_encode(hash_hmac('sha256', hash('sha256', $body.'@'.$timestamp, true), (string) base64_decode(self::WEBHOOK_KEY), true));

        self::assertFalse($transmission->notify('platform', $body, ['Afnor-Signature' => base64_encode('forged'), 'Afnor-Signature-Timestamp' => $timestamp]), 'a forged signature');
        self::assertSame($invoice, $transmission->notify('platform', $body, ['Afnor-Signature' => $signature, 'Afnor-Signature-Timestamp' => $timestamp]));
        self::assertSame('deposited', $transmission->flowOf($invoice)->getStatus());
    }

    public function testTheBuyersStatusesAreReceivedFromTheShopsOwnCursor(): void
    {
        // A platform that has, for the shop, a buyer's refusal of one of its invoices and a supplier's invoice (ledger's).
        $factory = new class extends GatewayFactory {
            protected function populateConfig(Config $config): void
            {
                $config->defaults([
                    'omnibill.factory_name' => 'inbox',
                    'omnibill.factory_title' => 'Inbox',
                    'omnibill.capabilities' => new Capabilities(approved: true),
                    'omnibill.action.receive' => new class implements ActionInterface {
                        public function supports(Request $request): bool
                        {
                            return $request instanceof Receive;
                        }

                        public function execute(Request $request): void
                        {
                            \assert($request instanceof Receive);
                            $items = null === $request->cursor ? ($GLOBALS['__marketplace_inbox'] ?? []) : [];
                            $GLOBALS['__marketplace_cursors'][] = $request->cursor;
                            $GLOBALS['__marketplace_kinds'][] = $request->kinds;
                            $request->setResult(new Inbox($items, 'c-1', false));
                        }
                    },
                ]);
            }
        };
        $registry = $this->registry($factory);
        $transmission = $this->transmission($registry);
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company());
        $this->entityManager->persist($row = new InvoiceFlow($invoice, 'platform', 'FLW-9'));
        $row->setHistory([InvoiceTransmission::row(new StatusChange(LifecycleStatus::DEPOSITED, $invoice->getNumber(), new \DateTimeImmutable('2026-11-11T09:00:00Z')))]);
        $this->entityManager->flush();

        $GLOBALS['__marketplace_inbox'] = [
            new Received(new Flow('IN-1', FlowKind::LIFECYCLE, Direction::IN), status: new StatusChange(LifecycleStatus::REFUSED, $invoice->getNumber(), new \DateTimeImmutable('2026-11-12T08:00:00Z'), 'Commande non reçue')),
            new Received(new Flow('IN-2', FlowKind::INVOICE, Direction::IN)),
        ];
        $GLOBALS['__marketplace_cursors'] = [];
        self::assertSame(1, $transmission->receiveStatuses());
        self::assertSame(0, $transmission->receiveStatuses(), 'from the cursor kept: nothing twice');
        self::assertSame([null, 'c-1'], $GLOBALS['__marketplace_cursors']);
        self::assertSame([[FlowKind::LIFECYCLE], [FlowKind::LIFECYCLE]], $GLOBALS['__marketplace_kinds'], 'the lifecycle flows only: the supplier\'s invoice is ledger\'s');
        self::assertSame(['refused', 'Commande non reçue'], [$row->getStatus(), $row->getReason()]);
        self::assertStringContainsString('Commande non reçue', (string) $invoices->guidance($invoice));
        unset($GLOBALS['__marketplace_inbox'], $GLOBALS['__marketplace_cursors'], $GLOBALS['__marketplace_kinds']);
    }

    public function testOnlyTheLifecycleFlowsAreAskedOfThePlatform(): void
    {
        $this->answers = [self::search([])];

        self::assertSame(0, $this->transmission()->receiveStatuses());

        self::assertSame(['POST /flow-service/v1/flows/search'], $this->calls());
        self::assertSame(['flowDirection' => ['In'], 'flowType' => ['CustomerInvoiceLC', 'SupplierInvoiceLC']], json_decode($this->requests[0]['body'], true)['where'], 'the suppliers\' invoices are not even listed');
    }

    public function testADepositWhoseAnswerIsLostIsLookedUpNeverSentTwice(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);
        $number = $invoice->getNumber();

        // The deposit leaves and the platform answers 503: it may have taken it. Asked at once by the
        // invoice's number (its trackingId): not listed yet.
        $this->answers = [self::directory(), new MockResponse('upstream timed out', ['http_code' => 503]), self::search([])];
        try {
            $invoices->send($invoice);
            self::fail('An outcome unknown is no success.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString($number, $e->getMessage());
            self::assertInstanceOf(OutcomeUnknownException::class, $e->getPrevious());
        }
        self::assertSame(['POST /directory-service/v1/directory-line/search', 'POST /flow-service/v1/flows', 'POST /flow-service/v1/flows/search'], $this->calls());
        self::assertSame(['trackingId' => $number, 'flowDirection' => ['Out']], json_decode($this->requests[2]['body'], true)['where']);
        $row = $transmission->flowOf($invoice);
        self::assertTrue($row->isOutcomeUnknown());
        self::assertSame(InvoiceFlow::OUTCOME_UNKNOWN, $invoices->lifecycleStatus($invoice));
        self::assertStringContainsString($number, (string) $invoices->guidance($invoice), 'the back office says what happened');
        self::assertNull($invoice->getSentAt());

        // Sent again at once: looked up first, still not listed - nothing deposited, "none" being too soon to trust.
        $this->requests = [];
        $this->answers = [self::directory(), self::search([])];
        try {
            $invoices->send($invoice);
            self::fail('Not sent again while the platform may have it.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString($number, $e->getMessage());
        }
        self::assertSame(['POST /directory-service/v1/directory-line/search', 'POST /flow-service/v1/flows/search'], $this->calls(), 'no second deposit');

        // The catch-up finds it: kept as if its answer had come back.
        $this->requests = [];
        $this->answers = [self::search([$this->deposited($number)])];
        $tester = new CommandTester(new InvoiceStatusCommand($transmission, $this->entityManager));
        $tester->execute(['numbers' => [$number], '--no-receive' => true, '--no-send' => true]);
        self::assertSame(['POST /flow-service/v1/flows/search'], $this->calls());
        self::assertFalse($row->isOutcomeUnknown());
        self::assertSame([$this->flowId, 'deposited'], [$row->getReference(), $row->getStatus()]);
        self::assertSame(Invoice::STATE_SENT, $invoice->getState());

        // Deposited once: a new submission is refused as for any invoice on the platform.
        $this->requests = [];
        try {
            $invoices->send($invoice);
            self::fail('A second deposit is refused.');
        } catch (TransmissionException) {
            self::assertSame([], $this->requests);
        }
    }

    public function testADepositThePlatformNeverGotGoesAgainOnceItSaysSo(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);
        $this->answers = [self::directory(), new MockResponse('', ['error' => 'Idle timeout reached for "https://api.flow.pa.example/flow-service/v1/flows".']), self::search([])];
        try {
            $invoices->send($invoice);
            self::fail('An outcome unknown is no success.');
        } catch (TransmissionException) {
        }
        // Long enough ago for the platform's search to list what it took.
        $row = $transmission->flowOf($invoice);
        (new \ReflectionProperty(InvoiceFlow::class, 'updatedAt'))->setValue($row, (new \DateTimeImmutable())->modify('-1 hour'));

        $this->requests = [];
        $this->answers = [self::directory(), self::search([]), new MockResponse('{"flowId":"'.$this->flowId.'","submittedAt":"2026-11-11T10:00:00Z"}', ['http_code' => 202])];
        $invoices->send($invoice);

        self::assertSame(['POST /directory-service/v1/directory-line/search', 'POST /flow-service/v1/flows/search', 'POST /flow-service/v1/flows'], $this->calls(), 'looked up, then deposited: the platform said it had none');
        self::assertSame($invoice->getNumber(), self::multipart($this->requests[2]['body'])['info']['trackingId']);
        self::assertFalse($row->isOutcomeUnknown());
        self::assertSame($this->flowId, $row->getReference());
        self::assertSame($row, $transmission->flowOf($invoice), 'one row per invoice');
    }

    public function testALookupWithNoAnswerToRelyOnLeavesTheDepositToAPersonWhoConfirmsIt(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);

        // The deposit's answer lost, and the platform's search unreachable when asked: asked again later, nothing offered.
        $this->answers = [self::directory(), new MockResponse('upstream timed out', ['http_code' => 503]), new MockResponse('unavailable', ['http_code' => 503])];
        try {
            $invoices->send($invoice);
            self::fail('An outcome unknown is no success.');
        } catch (TransmissionException) {
        }
        $row = $transmission->flowOf($invoice);
        self::assertSame([true, false], [$row->isOutcomeUnknown(), $row->isLookupInconclusive()]);
        self::assertFalse($invoices->awaitsConfirmation($invoice), 'an unreachable platform is asked again, not left to a person');
        try {
            $invoices->confirmDeposit($invoice, true, $this->flowId);
            self::fail('Nothing to confirm while the platform can still be asked.');
        } catch (TransmissionException) {
        }

        // Asked again: the platform refuses its search - an answer that tells nothing. Now a person settles it.
        $this->requests = [];
        $this->answers = [new MockResponse('{"errorCode":"INVALID_FILTER","errorMessage":"trackingId not searchable"}', ['http_code' => 400])];
        try {
            $invoices->refresh($invoice);
            self::fail('A lookup refused is no answer.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
        }
        self::assertTrue($row->isLookupInconclusive());
        self::assertTrue($row->isOutcomeUnknown(), 'still unknown');
        self::assertTrue($invoices->awaitsConfirmation($invoice));
        self::assertStringContainsString('HTTP 400', (string) $invoices->guidance($invoice), 'the back office says what the lookup answered, and what to do');

        // Confirmed without the flow id: refused, the flow is followed by it.
        try {
            $invoices->confirmDeposit($invoice, true, ' ');
            self::fail('The flow id is needed.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString($invoice->getNumber(), $e->getMessage());
        }
        self::assertTrue($row->isOutcomeUnknown());

        // The person found it on the platform: kept under that flow, its status asked of the platform; nothing deposited.
        $this->requests = [];
        $this->answers = [$this->flow('Ok')];
        $invoices->confirmDeposit($invoice, true, $this->flowId);
        self::assertSame(['GET /flow-service/v1/flows/'.$this->flowId], $this->calls());
        self::assertSame([false, $this->flowId, 'deposited'], [$row->isOutcomeUnknown(), $row->getReference(), $row->getStatus()]);
        self::assertSame(Invoice::STATE_SENT, $invoice->getState());
        self::assertFalse($invoices->awaitsConfirmation($invoice));

        // Deposited once: a new submission is refused as for any invoice on the platform.
        $this->requests = [];
        try {
            $invoices->send($invoice);
            self::fail('A second deposit is refused.');
        } catch (TransmissionException) {
            self::assertSame([], $this->requests);
        }
    }

    public function testADepositAPersonFindsAbsentIsDepositedAgainOnce(): void
    {
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company(), paid: false);

        // The deposit's answer lost, and the lookup at once refused by the platform: left to a person.
        $this->answers = [self::directory(), new MockResponse('upstream timed out', ['http_code' => 503]), new MockResponse('{"errorCode":"INVALID_FILTER"}', ['http_code' => 400])];
        try {
            $invoices->send($invoice);
            self::fail('An outcome unknown is no success.');
        } catch (TransmissionException) {
        }
        self::assertTrue($invoices->awaitsConfirmation($invoice));

        // Asked again, the platform answers: none yet - an answer, the lookup settles it (SEARCH_DELAY after), not a person.
        $this->answers = [self::search([])];
        $invoices->refresh($invoice);
        self::assertFalse($invoices->awaitsConfirmation($invoice));
        self::assertFalse($transmission->flowOf($invoice)->isLookupInconclusive());
        // Then its search refused again: back to the person.
        $this->answers = [new MockResponse('{"errorCode":"INVALID_FILTER"}', ['http_code' => 400])];
        try {
            $invoices->refresh($invoice);
            self::fail('A lookup refused is no answer.');
        } catch (TransmissionException) {
        }
        self::assertTrue($invoices->awaitsConfirmation($invoice));

        // Not on the platform, the person says: deposited again at once, once - no lookup first.
        $this->requests = [];
        $this->answers = [self::directory(), new MockResponse('{"flowId":"'.$this->flowId.'","submittedAt":"2026-11-11T10:00:00Z"}', ['http_code' => 202])];
        $invoices->confirmDeposit($invoice, false);

        self::assertSame(['POST /directory-service/v1/directory-line/search', 'POST /flow-service/v1/flows'], $this->calls());
        self::assertSame($invoice->getNumber(), self::multipart($this->requests[1]['body'])['info']['trackingId']);
        $row = $transmission->flowOf($invoice);
        self::assertSame([false, $this->flowId], [$row->isOutcomeUnknown(), $row->getReference()]);
        self::assertCount(1, $this->entityManager->getRepository(InvoiceFlow::class)->findBy(['invoice' => $invoice]), 'one row per invoice');

        // Answered again: nothing waits for it, nothing deposited twice.
        $this->requests = [];
        try {
            $invoices->confirmDeposit($invoice, false);
            self::fail('Nothing to confirm any more.');
        } catch (TransmissionException) {
        }
        self::assertSame([], $this->requests);
    }

    public function testAStatusWhoseAnswerIsLostIsNotSentAgainByItself(): void
    {
        $this->writer = new class implements LifecycleMessageInterface {
            public function write(Invoice $invoice, string $status, \DateTimeImmutable $at, ?string $reason = null, ?string $amount = null, array $amountsByRate = [], ?string $recipientAddress = null): ?array
            {
                return ['content' => '<written-by-the-application/>', 'filename' => 'status-'.$invoice->getNumber().'.xml'];
            }
        };
        $transmission = $this->transmission();
        $invoices = $this->invoices($transmission);
        $invoice = $this->invoice($invoices, self::company());

        // Paid with the order: "Encaissée" declared on the deposit; its message leaves, the answer is a 502.
        $this->answers = [self::directory(), new MockResponse('{"flowId":"'.$this->flowId.'"}', ['http_code' => 202]), new MockResponse('bad gateway', ['http_code' => 502])];
        $invoices->send($invoice);

        self::assertCount(3, $this->requests);
        $row = $transmission->flowOf($invoice);
        $pending = array_values($row->pending());
        self::assertSame(['paid', true], [$pending[0]['status'], $pending[0]['unknown'] ?? false]);
        self::assertStringContainsString('502', (string) $pending[0]['error']);
        self::assertStringContainsString('502', (string) $invoices->guidance($invoice), 'the back office says it may have been taken');

        self::assertSame(0, $transmission->sendPending(), 'the catch-up leaves it to a person');
        self::assertCount(3, $this->requests, 'nothing sent twice');
    }

    public function testPaidIsDeclaredWithTheAmountReceivedAtEachRate(): void
    {
        // A platform that takes the deposit and records the statuses declared to it.
        $factory = new class extends GatewayFactory {
            protected function populateConfig(Config $config): void
            {
                $config->defaults([
                    'omnibill.factory_name' => 'inbox',
                    'omnibill.factory_title' => 'Recorder',
                    'omnibill.capabilities' => new Capabilities(approved: true),
                    'omnibill.action.submit' => new class implements ActionInterface {
                        public function supports(Request $request): bool
                        {
                            return $request instanceof Submit;
                        }

                        public function execute(Request $request): void
                        {
                            $request->setResult(new Flow('FLW-R', state: \Omnibill\Model\FlowState::ACCEPTED, status: LifecycleStatus::DEPOSITED, history: [new StatusChange(LifecycleStatus::DEPOSITED)]));
                        }
                    },
                    'omnibill.action.set_status' => new class implements ActionInterface {
                        public function supports(Request $request): bool
                        {
                            return $request instanceof SetStatus;
                        }

                        public function execute(Request $request): void
                        {
                            \assert($request instanceof SetStatus);
                            $GLOBALS['__marketplace_statuses'][] = $request->change;
                            $request->setResult(new Flow('ST-1', FlowKind::LIFECYCLE, status: $request->change->status, history: [...($request->flow->history ?? []), $request->change]));
                        }
                    },
                ]);
            }
        };
        $this->writer = new class implements LifecycleMessageInterface {
            public function write(Invoice $invoice, string $status, \DateTimeImmutable $at, ?string $reason = null, ?string $amount = null, array $amountsByRate = [], ?string $recipientAddress = null): ?array
            {
                return ['content' => '<written-by-the-application/>', 'filename' => 'status.xml'];
            }
        };
        $transmission = $this->transmission($this->registry($factory));
        $invoice = $this->invoice($this->invoices($transmission), self::company());
        $GLOBALS['__marketplace_statuses'] = [];

        $transmission->submit($invoice, '%PDF-1.7 the invoice', 'facture.pdf', null, null, ['rule' => 'B2B', 'buyer_address' => ['scheme' => '0225', 'id' => self::BUYER_SIREN]]);

        [$paid] = $GLOBALS['__marketplace_statuses'];
        $total = InvoiceTransmission::decimal($invoice->getTotal(), 'EUR');
        self::assertSame([LifecycleStatus::PAID, $total, [2000 => $total]], [$paid->status, $paid->amount, $paid->amounts], 'the whole amount, received at 20 %');
        self::assertSame('paid', $transmission->flowOf($invoice)->getStatus());
        unset($GLOBALS['__marketplace_statuses']);
    }
}
