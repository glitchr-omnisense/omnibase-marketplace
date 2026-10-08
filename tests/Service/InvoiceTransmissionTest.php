<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Base\Marketplace\Service\Invoices;
use Omnibill\Email\EmailGatewayFactory;
use Omnibill\Registry;
use Symfony\Component\Mime\Email;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The shop's invoices through glitchr/omnibill: sent by omnibill/email
 * when nothing else is configured - the seller's address, the bundle's
 * words, the PDF (Factur-X) attached -, their lifecycle statuses kept on
 * them and read back by the gateway; a site's own omnibill gateway taken
 * when it names one. Skipped without glitchr/omnibill and omnibill/email.
 */
final class InvoiceTransmissionTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private Invoices $invoices;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(EmailGatewayFactory::class)) {
            self::markTestSkipped('Needs glitchr/omnibill and omnibill/email.');
        }
        parent::setUp();
        if (!FacturX::available() || !class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs horstoeko/zugferd and dompdf/dompdf.');
        }
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

    public function testSentByOmnibillEmailUnconfiguredItsStatusesKeptOnTheInvoice(): void
    {
        self::assertSame('email', $this->invoices->gateway(), 'omnibill/email by default');
        $invoice = $this->invoices->issue($this->order());

        $this->invoices->send($invoice);

        $messages = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), $invoice->getNumber())));
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertSame('factures@example.org', $email->getFrom()[0]->getAddress(), 'from the seller (marketplace.invoice.seller.email)');
        self::assertSame($invoice->getBuyer()['email'], $email->getTo()[0]->getAddress());
        self::assertSame(self::getContainer()->get('translator')->trans('@marketplace.invoice.mail.subject', ['{number}' => $invoice->getNumber()]), $email->getSubject(), 'the bundle\'s words');
        self::assertStringContainsString('/factures/'.$invoice->getNumber(), (string) $email->getTextBody(), 'its signed link');
        $attachment = $email->getAttachments()[0];
        self::assertSame($invoice->getNumber().'.pdf', $attachment->getFilename());
        self::assertNotNull(FacturX::extract($attachment->getBody()), 'the PDF carries its Factur-X');

        $transmission = self::getContainer()->get(InvoiceTransmission::class);
        $this->entityManager->clear();
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        $flow = $transmission->flowOf($invoice);
        self::assertSame(['email', 'sent'], [$flow->getGateway(), $flow->getStatus()]);
        self::assertStringEndsWith('@omnibill', $flow->getReference(), 'the message\'s id');
        self::assertNotNull($invoice->getSentAt());
        self::assertSame(Invoice::STATE_PAID, $invoice->getState(), 'paid with the order: still paid once sent');

        // Paid: declared to the gateway, which keeps it (omnibill/email: beside the invoice, InvoiceStatusStore).
        $this->invoices->markPaid($invoice);
        self::assertSame(['sent', 'paid'], array_column($transmission->flowOf($invoice)->getHistory(), 'status'));
        self::assertSame(212, $transmission->flowOf($invoice)->getHistory()[1]['code'], '"Encaissée", the reform\'s 212');
        self::assertStringContainsString('(email)', (string) $this->invoices->lifecycle($invoice));

        // Asked again in another request: the gateway reads what was kept.
        $this->entityManager->clear();
        $invoice = $this->entityManager->find(Invoice::class, $invoice->getId());
        self::assertTrue($this->invoices->refresh($invoice));
        self::assertSame('paid', $transmission->flowOf($invoice)->getStatus());
        self::assertSame(['sent', 'paid'], array_map(static fn ($c) => $c->status->value, self::getContainer()->get(\Omnibill\Email\StatusStoreInterface::class)->history($flow->getReference())), 'omnibill/email\'s store reads it');
    }

    public function testASitesOwnGatewayIsTakenWithItsWords(): void
    {
        $container = self::getContainer();
        $store = $container->get(\Omnibill\Email\StatusStoreInterface::class);
        $registry = new Registry([new EmailGatewayFactory($container->get('mailer.mailer'), $store)], [
            'accounting' => ['factory' => 'email', 'options' => ['from' => 'compta@example.org', 'subject' => 'Facture {number} de {seller}', 'bcc' => ['archives@example.org']]],
        ]);
        $transmission = new InvoiceTransmission($this->entityManager, $container->get('translator'), $registry, $container->get('mailer.mailer'), $store, 'accounting');
        $invoice = $this->invoices->issue($this->order());

        $flow = $transmission->submit($invoice, '%PDF-1.7 test', 'facture.pdf', 'https://example.org/f');

        $messages = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), $invoice->getNumber())));
        self::assertCount(1, $messages);
        self::assertSame('compta@example.org', $messages[0]->getFrom()[0]->getAddress());
        self::assertSame('Facture '.$invoice->getNumber().' de Glitch Art Studio', $messages[0]->getSubject(), 'the site\'s subject kept');
        self::assertStringContainsString('https://example.org/f', (string) $messages[0]->getTextBody(), 'the bundle\'s text where the site wrote none');
        self::assertSame('archives@example.org', $messages[0]->getBcc()[0]->getAddress());
        self::assertSame(['accounting', 'sent'], [$flow->getGateway(), $flow->getStatus()]);
        self::assertSame($flow, $transmission->flowOf($invoice));
    }

    public function testWithoutTheSellersAddressNothingIsSentAndItSaysWhy(): void
    {
        $container = self::getContainer();
        $transmission = new InvoiceTransmission($this->entityManager, $container->get('translator'), null, $container->get('mailer.mailer'), null, 'email');
        $invoice = $this->invoices->issue($this->order());
        (new \ReflectionProperty(Invoice::class, 'seller'))->setValue($invoice, ['email' => null] + $invoice->getSeller());

        try {
            $transmission->submit($invoice, '%PDF', 'f.pdf');
            self::fail('No "from": refused.');
        } catch (\Base\Marketplace\Invoice\Transmission\TransmissionException $e) {
            self::assertStringContainsString('marketplace.invoice.seller.email', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e, 'the back office tells it as a refusal');
        }
        self::assertNull($transmission->flowOf($invoice));
        $this->entityManager->refresh($invoice);
    }
}
