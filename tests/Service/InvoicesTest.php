<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\EventListener\InvoiceOnPaymentListener;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Service\Invoices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Tests\Base\Marketplace\Http\BackOfficeFormTrait;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The shop's invoices: issued for a paid order, numbered without a gap -
 * under concurrency too -, frozen once issued, cancelled by a credit note
 * only; their PDF carrying a Factur-X XML the schema accepts.
 */
final class InvoicesTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;
    use BackOfficeFormTrait;

    private Invoices $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = self::getContainer()->get(Invoices::class);
    }

    /** An order paid: two lines at 20 % (their VAT computed by the order), shipping paid with them. */
    private function order(int $shipping = 590): Order
    {
        $buyer = $this->user('buyer');
        $order = new Order($this->store());
        $order->setCustomer($buyer);
        $order->setRegion($this->region);
        $this->entityManager->persist($order);
        foreach ([[$this->goods('vase', 1900), 2], [$this->goods('bol', 1000), 1]] as [$product, $quantity]) {
            $item = new OrderItem($product, $quantity);
            $order->addItem($item);
            $item->setVatCharge((int) round($item->getSalePrice() * 0.2));
            $this->entityManager->persist($item);
        }
        $order->setShippingCharge($shipping);
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

    public function testAPaidOrderIsInvoicedOnceWithTheOrdersAmounts(): void
    {
        $order = $this->order();
        $invoice = $this->invoices->issue($order);

        self::assertMatchesRegularExpression('/^F-\d{4}-\d{6}$/', $invoice->getNumber());
        self::assertSame('Glitch Art Studio', $invoice->getSeller()['name'], 'marketplace.invoice.seller');
        self::assertSame('123456789', $invoice->getSeller()['siren']);
        self::assertSame($order->getCustomer()->getEmail(), $invoice->getBuyer()['email']);
        self::assertCount(2, $invoice->getLines());
        self::assertSame([3800, 760, 4560], [$invoice->getLines()[0]['total'], $invoice->getLines()[0]['vat'], $invoice->getLines()[0]['total_with_vat']]);
        self::assertEquals(20.0, $invoice->getLines()[0]['rate']);

        $totals = $invoice->getTotals();
        self::assertSame(4800, $totals['lines'], 'the lines before VAT, as the order has them');
        self::assertSame([492, 98], [$totals['charges'][0]['total'], $totals['charges'][0]['vat']], 'shipping paid 5,90 € VAT included: 4,92 € and its VAT at the lines\' rate');
        self::assertSame($order->getNetPrice(), $totals['total'], 'exactly what the order cost');
        self::assertSame(4800 + 492, $totals['total_without_vat']);
        self::assertSame(960 + 98, $totals['vat']);
        self::assertSame(0, $totals['due']);
        self::assertTrue($invoice->isPaid(), 'the order is paid: the invoice is "acquittée"');
        self::assertSame(Invoice::STATE_PAID, $invoice->getState());
        self::assertNotEmpty(array_filter($invoice->getMentions(), fn ($m) => str_starts_with($m, 'Facture acquittée le')));
        self::assertContains('Nature des opérations : livraisons de biens.', $invoice->getMentions(), 'a mention required since 2026-09-01');
        self::assertContains('Escompte pour paiement anticipé : néant.', $invoice->getMentions());

        try {
            $this->invoices->issue($order);
            self::fail('An order has one invoice.');
        } catch (\LogicException $e) {
            self::assertStringContainsString($invoice->getNumber(), $e->getMessage());
        }
    }

    public function testAnIssuedInvoiceIsFrozen(): void
    {
        $order = $this->order(0);
        $invoice = $this->invoices->issue($order);
        $id = $invoice->getId();
        $email = $invoice->getBuyer()['email'];

        // What it was written from changes: the invoice does not.
        $order->getCustomer()->setEmail('someone-else-'.bin2hex(random_bytes(3)).'@example.org');
        $this->store()->setVatNumber('FR40303265045');
        $this->entityManager->flush();
        $this->entityManager->clear();
        $invoice = $this->entityManager->find(Invoice::class, $id);
        self::assertSame($email, $invoice->getBuyer()['email']);
        self::assertSame('FR40123456789', $invoice->getSeller()['vat_number']);

        // Nor can it be changed: Doctrine refuses the update.
        (new \ReflectionProperty(Invoice::class, 'lines'))->setValue($invoice, []);
        try {
            $this->entityManager->flush();
            self::fail('An issued invoice is not updated.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('credit note', $e->getMessage());
        }
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertNotEmpty($this->entityManager->find(Invoice::class, $id)->getLines());
    }

    public function testACreditNoteCancelsTheInvoiceAndTheOrderMayBeInvoicedAgain(): void
    {
        $order = $this->order();
        $invoice = $this->invoices->issue($order);
        $creditNote = $this->invoices->credit($invoice, 'Colis retourné');

        self::assertTrue($creditNote->isCreditNote());
        self::assertMatchesRegularExpression('/^A-\d{4}-\d{6}$/', $creditNote->getNumber());
        self::assertSame($invoice, $creditNote->getCredited());
        self::assertSame($invoice->getTotal(), $creditNote->getTotal());
        self::assertSame($invoice->getLines(), $creditNote->getLines());
        self::assertTrue($invoice->isCancelled());
        self::assertSame($creditNote, $invoice->getCreditNote());
        self::assertContains('Avoir annulant la facture '.$invoice->getNumber().' du '.$invoice->getIssuedAt()->format('d/m/Y').'.', $creditNote->getMentions());
        self::assertContains('Colis retourné', $creditNote->getMentions());

        try {
            $this->invoices->credit($invoice);
            self::fail('An invoice is cancelled once.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $again = $this->invoices->issue($order);
        self::assertSame($invoice->getSequence() + 1, $again->getSequence(), 'the next number: no gap');
        self::assertSame($again, $this->invoices->of($order));
    }

    public function testNumbersFollowWithoutAGapUnderConcurrency(): void
    {
        $orders = [];
        for ($i = 0; $i < 6; ++$i) {
            $orders[] = $this->order(0)->getReference();
        }
        $year = (int) date('Y');
        $before = $this->invoices->repository()->lastSequence('F', $year);

        $processes = [];
        foreach ($orders as $reference) {
            $process = new Process(['php', 'bin/console', 'marketplace:invoice:issue', $reference, '--env=test'], self::$kernel->getProjectDir());
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }
        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $numbers[] = trim(explode(' ', trim($process->getOutput()))[1] ?? '');
        }

        $this->entityManager->clear();
        $sequences = array_map(fn (Invoice $i) => $i->getSequence(), $this->invoices->repository()->findBy(['series' => 'F', 'year' => $year]));
        sort($sequences);
        self::assertSame(range(1, $before + 6), $sequences, 'every number once, none missing');
        self::assertCount(6, array_unique($numbers));
    }

    public function testThePdfCarriesAValidFacturX(): void
    {
        if (!FacturX::available() || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs horstoeko/zugferd and dompdf/dompdf.');
        }
        $invoice = $this->invoices->issue($this->order());

        $pdf = $this->invoices->pdf($invoice);
        self::assertStringStartsWith('%PDF-', $pdf);
        $xml = FacturX::extract($pdf);
        self::assertNotNull($xml, 'factur-x.xml attached');
        self::assertStringContainsString($invoice->getNumber(), $xml);
        self::assertStringContainsString('urn:cen.eu:en16931:2017', $xml, 'the profile EN 16931');
        self::assertSame([], self::getContainer()->get(FacturX::class)->validate($xml), 'the schema accepts it');
        self::assertStringContainsString('<ram:GrandTotalAmount>'.number_format($invoice->getTotal() / 100, 2, '.', '').'</ram:GrandTotalAmount>', $xml);

        $creditNote = $this->invoices->credit($invoice);
        $creditXml = $this->invoices->xml($creditNote);
        self::assertSame([], self::getContainer()->get(FacturX::class)->validate($creditXml));
        self::assertStringContainsString('<ram:TypeCode>381</ram:TypeCode>', $creditXml, 'a credit note');
        self::assertStringContainsString($invoice->getNumber(), $creditXml, 'it names the invoice it cancels');
    }

    public function testTheBuyerOrASignedLinkDownloadsIt(): void
    {
        if (!FacturX::available() || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs horstoeko/zugferd and dompdf/dompdf.');
        }
        $invoice = $this->invoices->issue($this->order(0));
        $path = '/factures/'.$invoice->getNumber();

        self::assertContains(self::$kernel->handle(Request::create($path))->getStatusCode(), [302, 401, 403], 'nobody signed in');

        $signed = self::$kernel->handle(Request::create($this->invoices->signedUrl($invoice)));
        self::assertSame(200, $signed->getStatusCode());
        self::assertSame('application/pdf', $signed->headers->get('Content-Type'));
        self::assertStringContainsString($invoice->getNumber().'.pdf', (string) $signed->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $signed->getContent());

        self::assertContains(self::$kernel->handle(Request::create(str_replace('_hash=', '_hash=x', $this->invoices->signedUrl($invoice))))->getStatusCode(), [302, 401, 403], 'a link tampered with: the sign-in, not the invoice');
    }

    public function testACancelledInvoiceStillReadsAsAnInvoice(): void
    {
        $invoice = $this->invoices->issue($this->order(0));
        $creditNote = $this->invoices->credit($invoice);

        self::assertStringContainsString('>Facture<', $this->invoices->html($invoice), 'cancelled, it is still an invoice: its credit note is another document');
        self::assertStringContainsString('>Avoir<', $this->invoices->html($creditNote));
    }

    public function testTheBuyersOrderPageLinksItsInvoices(): void
    {
        $order = $this->order(0);
        $invoice = $this->invoices->issue($order);
        $creditNote = $this->invoices->credit($invoice);
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';
        $buyer = $order->getCustomer();
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', serialize(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($buyer, 'main', $buyer->getRoles())));
        $session->save();

        $page = self::$kernel->handle(Request::create('/commandes/'.$order->getReference(), 'GET', [], [$session->getName() => $session->getId()]));
        self::assertSame(200, $page->getStatusCode());
        $translator = self::getContainer()->get('translator');
        foreach (['@marketplace.invoice.invoice' => $invoice, '@marketplace.invoice.credit_note' => $creditNote] as $label => $document) {
            self::assertMatchesRegularExpression('#<a href="/factures/'.preg_quote($document->getNumber(), '#').'/?\?download=1"><i class="fa-solid fa-file-pdf"></i> '.preg_quote($translator->trans($label), '#').' '.preg_quote($document->getNumber(), '#').'</a>#', (string) $page->getContent());
        }
    }

    public function testAnOrderPaidIsInvoicedByItselfWhenTheShopSaysSo(): void
    {
        $order = $this->order();
        (new InvoiceOnPaymentListener($this->invoices, false))(new OrderPaidEvent($order));
        self::assertNull($this->invoices->of($order), 'off by default');

        (new InvoiceOnPaymentListener($this->invoices, true))(new OrderPaidEvent($order));
        self::assertNotNull($invoice = $this->invoices->of($order));

        (new InvoiceOnPaymentListener($this->invoices, true))(new OrderPaidEvent($order));
        self::assertSame($invoice, $this->invoices->of($order), 'a second confirmation issues nothing more');
    }

    /** The token an admin action's form posts, in the signed-in session. */
    private function actionToken(string $method): string
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->setId((string) reset($this->cookies));
        $session->start();
        $request = Request::create('/admin');
        $request->setSession($session);
        $stack = self::getContainer()->get('request_stack');
        $stack->push($request);
        $token = self::getContainer()->get('security.csrf.token_manager')->getToken(\Base\Admin\Attribute\AdminAction::tokenId($method))->getValue();
        $stack->pop();
        $session->save();

        return $token;
    }

    public function testTheBackOfficeIssuesListsAndCreditsThem(): void
    {
        $this->signInToTheBackOffice();
        $order = $this->order();

        $issued = $this->ask('/admin/orders/'.$order->getId().'/invoice', ['_token' => $this->actionToken('issueInvoice')]);
        self::assertTrue($issued->isRedirection(), (string) $issued->getStatusCode().' '.substr(strip_tags((string) $issued->getContent()), 0, 800));
        $this->entityManager->clear();
        $invoice = $this->invoices->of($this->entityManager->find(Order::class, $order->getId()));
        self::assertNotNull($invoice, 'issued from the order\'s screen');

        $index = $this->open('/admin/invoices');
        self::assertSame(200, $index->getStatusCode());
        self::assertStringContainsString($invoice->getNumber(), (string) $index->getContent());

        $credited = $this->ask('/admin/invoices/'.$invoice->getId().'/credit', ['_token' => $this->actionToken('credit')]);
        self::assertTrue($credited->isRedirection(), (string) $credited->getStatusCode());
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(Invoice::class, $invoice->getId())->isCancelled(), 'cancelled by its credit note');
        self::assertSame(404, $this->ask('/admin/invoices/'.$invoice->getId().'/edit')->getStatusCode(), 'never edited');
    }
}
