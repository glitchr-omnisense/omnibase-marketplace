<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Base\Marketplace\Invoice\Transmission\TransmissionException;
use Base\Marketplace\Service\Invoices;
use Omnibill\Email\EmailGatewayFactory;
use Omnibill\Email\StatusStoreInterface;
use Omnibill\Registry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Tests\Base\Marketplace\Http\BackOfficeFormTrait;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * What the platform cannot settle after an answer lost, settled by a person
 * on the invoice's screen of the back office, as a browser posts it: the
 * site's own gateway (omnibill/email, which cannot look a deposit up), the
 * container's services, the admin actions behind their CSRF token.
 *
 *   - a deposit whose outcome is unknown, blocked until then: « Reçue par la
 *     plateforme » with the flow id the platform shows, or « Absente :
 *     déposer de nouveau », deposited once;
 *   - a status declared whose answer was lost: « Reçu par la plateforme »,
 *     or « Absent : renvoyer », sent once.
 *
 * Skipped without omnisense/omnibill and omnibill/email.
 */
final class InvoiceConfirmationTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;
    use TransmissionSchemaTrait;
    use BackOfficeFormTrait;

    private Invoices $invoices;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(EmailGatewayFactory::class)) {
            self::markTestSkipped('Needs omnisense/omnibill and omnibill/email.');
        }
        parent::setUp();
        if (!FacturX::available() || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs horstoeko/zugferd and dompdf/dompdf.');
        }
        $this->createTransmissionSchema();
        $this->invoices = self::getContainer()->get(Invoices::class);
    }

    private function order(): Order
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
        $order->markAsPaidAt();
        $order->markAsConfirmed();
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $order;
    }

    /** An invoice whose deposit left through the e-mail gateway and whose answer was lost. */
    private function lostDeposit(): Invoice
    {
        $invoice = $this->invoices->issue($this->order());
        $this->entityManager->persist((new InvoiceFlow($invoice, 'email', ''))->outcomeUnknown('email'));
        $this->entityManager->flush();

        return $invoice;
    }

    /** An invoice sent by e-mail, and "Encaissée" declared about it, its answer lost. */
    private function lostStatus(): Invoice
    {
        $invoice = $this->invoices->issue($this->order());
        $this->invoices->send($invoice);
        $row = $this->transmission()->flowOf($invoice);
        $row->declaredUnknown($row->declare('paid', new \DateTimeImmutable('2026-11-11T10:00:00Z'), '45.60'), '[email] HTTP 502 to a write that may have been taken');
        $this->entityManager->flush();

        return $invoice;
    }

    private function transmission(): InvoiceTransmission
    {
        return self::getContainer()->get(InvoiceTransmission::class);
    }

    /** The invoice as the database has it now: the requests cleared the entity manager. */
    private function reread(Invoice $invoice): Invoice
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Invoice::class, $invoice->getId());
    }

    /** @return list<Email> the e-mails that carried that invoice */
    private function mailsOf(Invoice $invoice): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), $invoice->getNumber())));
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

    /** A label of the back office, in the language it speaks. */
    private static function label(string $key): string
    {
        return self::getContainer()->get('translator')->trans('@marketplace.invoice.admin.'.$key);
    }

    /** The invoice's screen, its text as a person reads it. */
    private function screen(Invoice $invoice): string
    {
        $response = $this->open('/admin/invoices/'.$invoice->getId());
        self::assertSame(200, $response->getStatusCode(), $this->said($response));

        return html_entity_decode((string) $response->getContent());
    }

    /** @param array<string, mixed> $fields */
    private function post(string $path, array $fields): Response
    {
        $response = $this->ask($path, $fields);
        self::assertTrue($response->isRedirection(), $this->said($response));

        return $response;
    }

    public function testADepositWhoseAnswerWasLostOnAGatewayThatCannotLookItUpIsConfirmedByAPerson(): void
    {
        $invoice = $this->lostDeposit();
        $number = $invoice->getNumber();
        self::assertTrue($this->invoices->awaitsConfirmation($invoice), 'omnibill/email cannot look a deposit up');
        // Blocked: never deposited again blindly.
        try {
            $this->invoices->send($invoice);
            self::fail('Not sent again while the platform may have it.');
        } catch (TransmissionException $e) {
            self::assertStringContainsString($number, $e->getMessage());
        }
        self::assertSame([], $this->mailsOf($invoice));

        $this->signInToTheBackOffice();
        $page = $this->screen($invoice);
        self::assertStringContainsString(self::label('deposit_taken'), $page);
        self::assertStringContainsString(self::label('deposit_again'), $page);
        self::assertStringContainsString('/admin/invoices/'.$invoice->getId().'/deposit', $page);
        self::assertStringContainsString('name="reference"', $page, 'the platform\'s flow id is asked for');

        // Without the token: refused, nothing changes.
        self::assertSame(403, $this->ask('/admin/invoices/'.$invoice->getId().'/deposit', ['deposited' => '1', 'reference' => 'FLW-1'])->getStatusCode());
        // Without the flow id: refused, nothing changes.
        $this->post('/admin/invoices/'.$invoice->getId().'/deposit', ['_token' => $this->actionToken('confirmDeposit'), 'deposited' => '1', 'reference' => ' ']);
        $invoice = $this->reread($invoice);
        self::assertTrue($this->transmission()->flowOf($invoice)->isOutcomeUnknown());

        // On the platform under FLW-1: kept as deposited, under that reference; nothing sent.
        $back = $this->post('/admin/invoices/'.$invoice->getId().'/deposit', ['_token' => $this->actionToken('confirmDeposit'), 'deposited' => '1', 'reference' => 'FLW-1']);
        self::assertStringContainsString('/admin/invoices/'.$invoice->getId(), (string) $back->headers->get('Location'), 'back to the invoice');
        $invoice = $this->reread($invoice);
        $row = $this->transmission()->flowOf($invoice);
        self::assertSame([false, 'FLW-1', 'sent'], [$row->isOutcomeUnknown(), $row->getReference(), $row->getStatus()]);
        self::assertNotNull($invoice->getSentAt());
        self::assertFalse($this->invoices->awaitsConfirmation($invoice));
        self::assertSame([], $this->mailsOf($invoice), 'nothing sent again');
        self::assertStringNotContainsString(self::label('deposit_again'), $this->screen($invoice), 'settled: no answer offered any more');
    }

    public function testADepositThePlatformDoesNotHaveIsDepositedAgainOnce(): void
    {
        $invoice = $this->lostDeposit();
        $this->signInToTheBackOffice();

        $this->post('/admin/invoices/'.$invoice->getId().'/deposit', ['_token' => $this->actionToken('confirmDeposit'), 'deposited' => '0']);

        $invoice = $this->reread($invoice);
        self::assertCount(1, $this->mailsOf($invoice), 'deposited again, once');
        $row = $this->transmission()->flowOf($invoice);
        self::assertSame([false, 'sent'], [$row->isOutcomeUnknown(), $row->getStatus()]);
        self::assertStringEndsWith('@omnibill', $row->getReference(), 'the new deposit\'s reference');
        self::assertCount(1, $this->entityManager->getRepository(InvoiceFlow::class)->findBy(['invoice' => $invoice]), 'one row per invoice');

        // The same answer again: nothing waits for it any more (the mails logged are this request's).
        $this->post('/admin/invoices/'.$invoice->getId().'/deposit', ['_token' => $this->actionToken('confirmDeposit'), 'deposited' => '0']);
        $invoice = $this->reread($invoice);
        self::assertSame([], $this->mailsOf($invoice), 'never twice');
        self::assertSame($row->getReference(), $this->transmission()->flowOf($invoice)->getReference());
    }

    public function testAStatusWhoseAnswerWasLostIsConfirmedReceivedByAPerson(): void
    {
        $invoice = $this->lostStatus();
        self::assertSame(0, $this->transmission()->sendPending(), 'the catch-up leaves it to a person');

        $this->signInToTheBackOffice();
        $page = $this->screen($invoice);
        self::assertStringContainsString(self::label('status_taken'), $page);
        self::assertStringContainsString(self::label('status_resend'), $page);
        self::assertStringContainsString('/admin/invoices/'.$invoice->getId().'/status', $page);
        self::assertStringContainsString('name="index" value="0"', $page);

        $this->post('/admin/invoices/'.$invoice->getId().'/status', ['_token' => $this->actionToken('confirmStatus'), 'index' => '0', 'taken' => '1']);

        $invoice = $this->reread($invoice);
        $row = $this->transmission()->flowOf($invoice);
        self::assertSame([true, false], [$row->getDeclared()[0]['sent'], $row->getDeclared()[0]['unknown']]);
        self::assertSame(['sent', 'paid'], array_column($row->getHistory(), 'status'), 'kept in the invoice\'s history');
        self::assertNull($row->getDeclared()[0]['flow'], 'nothing sent to the gateway: no flow of its answer');
        self::assertStringNotContainsString(self::label('status_resend'), $this->screen($invoice));
    }

    public function testAStatusThePlatformDoesNotHaveIsSentAgainOnce(): void
    {
        $invoice = $this->lostStatus();
        $reference = $this->transmission()->flowOf($invoice)->getReference();
        $store = self::getContainer()->get(StatusStoreInterface::class);
        $this->signInToTheBackOffice();

        $this->post('/admin/invoices/'.$invoice->getId().'/status', ['_token' => $this->actionToken('confirmStatus'), 'index' => '0', 'taken' => '0']);

        $invoice = $this->reread($invoice);
        $row = $this->transmission()->flowOf($invoice);
        self::assertSame([true, false], [$row->getDeclared()[0]['sent'], $row->getDeclared()[0]['unknown']]);
        self::assertSame(['sent', 'paid'], array_map(static fn ($c) => $c->status->value, $store->history($reference)), 'sent again to the gateway');
        self::assertSame($reference, $row->getDeclared()[0]['flow'], 'the gateway\'s answer kept');

        // Answered twice: nothing waits, nothing sent twice.
        $this->post('/admin/invoices/'.$invoice->getId().'/status', ['_token' => $this->actionToken('confirmStatus'), 'index' => '0', 'taken' => '0']);
        self::assertSame(['sent', 'paid'], array_map(static fn ($c) => $c->status->value, $store->history($reference)));
        self::assertFalse($this->invoices->confirmStatus($this->reread($invoice), 0, false));
    }
}
