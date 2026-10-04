<?php

namespace Tests\Base\Marketplace\Supply;

use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Event\SupplyJobChangedEvent;
use Base\Marketplace\Service\Supply;
use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyQuote;
use Base\Marketplace\Supply\Model\SupplyResult;
use Base\Marketplace\Supply\OfflineSupplier;
use Base\Marketplace\Supply\SupplierInterface;
use Base\Marketplace\Supply\SupplierRegistry;
use Base\Marketplace\Supply\SupplyException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/** A paid order's made-to-order lines, grouped by supplier and recipient, submitted and followed. */
final class SupplyTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    private const LEA = ['name' => 'Léa Martin', 'street' => ['12 rue des Lilas'], 'postcode' => '37000', 'city' => 'Tours', 'country' => 'FR'];
    private const TOM = ['name' => 'Tom Petit', 'street' => ['3 place du Marché'], 'postcode' => '69001', 'city' => 'Lyon', 'country' => 'FR'];

    private function printer(bool $fails = false): SupplierInterface
    {
        return new class($fails) implements SupplierInterface {
            /** @var list<SupplyOrder> */
            public array $submitted = [];

            public function __construct(public bool $fails)
            {
            }

            public static function name(): string { return 'printer'; }
            public function isConfigured(): bool { return true; }
            public function products(?string $query = null): array { return []; }
            public function quote(SupplyOrder $order): SupplyQuote { return new SupplyQuote(1000, 500); }

            public function submit(SupplyOrder $order, bool $draft = false): SupplyResult
            {
                if ($this->fails) {
                    throw new SupplyException('printer', 'Out of paper.');
                }
                $this->submitted[] = $order;

                return new SupplyResult('printer', 'job_'.\count($this->submitted).'_'.bin2hex(random_bytes(3)), $draft ? SupplyStatus::DRAFT : SupplyStatus::SUBMITTED, orderReference: $order->reference);
            }

            public function confirm(string $reference): SupplyResult { return new SupplyResult('printer', $reference, SupplyStatus::ACCEPTED); }
            public function fetch(string $reference): SupplyResult { return new SupplyResult('printer', $reference, SupplyStatus::SHIPPED, 'TRK1', 'https://track.test/TRK1', 'Colissimo'); }
            public function cancel(string $reference): SupplyResult { return new SupplyResult('printer', $reference, SupplyStatus::CANCELLED); }
            public function notify(string $body, array $headers = []): ?SupplyResult { return null; }
        };
    }

    public function testLinesAreGroupedBySupplierAndRecipient(): void
    {
        $printer = $this->printer();
        $moves = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SupplyJobChangedEvent::class, static function (SupplyJobChangedEvent $e) use (&$moves): void { $moves[] = $e->job->getStatus(); });
        $supply = new Supply($this->entityManager, new SupplierRegistry([$printer]), $dispatcher);

        $card = $this->goods('faire-part')->setSupplier('printer')->setSupplierReference('card-a5');
        $mug = $this->goods('mug')->setSupplier('printer')->setSupplierReference('mug-11oz');
        $book = $this->goods('livre');
        $order = $this->paidOrder($this->user('host'), [[$card, 50], [$mug, 1], [$card, 1], [$book, 1]]);
        $items = $order->getItems()->toArray();
        $items[0]->setRecipient(self::LEA)->setPersonalisation(['files' => ['https://site.test/front.pdf'], 'monogram' => 'L&H']);
        $items[1]->setRecipient(self::LEA);
        $items[2]->setRecipient(self::TOM);
        $this->entityManager->flush();

        $jobs = $supply->dispatch($order);
        self::assertCount(2, $jobs, 'one per recipient; the book is the shop\'s own to send');
        self::assertCount(2, $printer->submitted[0]->lines);
        self::assertSame('card-a5', $printer->submitted[0]->lines[0]->productReference);
        self::assertSame(50, $printer->submitted[0]->lines[0]->quantity);
        self::assertSame(['https://site.test/front.pdf'], $printer->submitted[0]->lines[0]->files);
        self::assertSame(['monogram' => 'L&H'], $printer->submitted[0]->lines[0]->options);
        self::assertSame('Tours', $printer->submitted[0]->recipient->city);
        self::assertSame('Lyon', $printer->submitted[1]->recipient->city);
        self::assertSame($order->getReference().'-1', $jobs[0]->getReference());
        self::assertSame(SupplyStatus::SUBMITTED, $jobs[0]->getStatus());
        self::assertSame([], $supply->dispatch($order), 'once');
        self::assertCount(2, $supply->jobsOf($order));

        $supply->sync($jobs[0]);
        self::assertSame(SupplyStatus::SHIPPED, $jobs[0]->getStatus());
        self::assertSame('TRK1', $jobs[0]->getTrackingNumber());
        self::assertSame('Colissimo', $jobs[0]->getCarrier());

        $applied = $supply->apply(new SupplyResult('printer', (string) $jobs[1]->getSupplierReference(), SupplyStatus::DELIVERED));
        self::assertSame($jobs[1]->getId(), $applied->getId());
        self::assertNull($supply->apply(new SupplyResult('printer', 'unknown', SupplyStatus::DELIVERED)));
        self::assertSame([SupplyStatus::SUBMITTED, SupplyStatus::SUBMITTED, SupplyStatus::SHIPPED, SupplyStatus::DELIVERED], $moves);
    }

    public function testAFailureIsKeptAndSentAgain(): void
    {
        $printer = $this->printer(true);
        $supply = new Supply($this->entityManager, new SupplierRegistry([$printer]));
        $card = $this->goods('faire-part')->setSupplier('printer');
        $order = $this->paidOrder($this->user('host'), [[$card, 10]]);
        $order->getItems()->first()->setRecipient(self::LEA);
        $this->entityManager->flush();

        [$job] = $supply->dispatch($order);
        self::assertSame(SupplyStatus::FAILED, $job->getStatus());
        self::assertSame('Out of paper.', $job->getMessage());
        self::assertTrue($this->entityManager->isOpen(), 'the payment is not undone');

        $printer->fails = false;
        self::assertSame(SupplyStatus::DRAFT, $supply->resubmit($job, true)->getStatus());
        self::assertSame(SupplyStatus::ACCEPTED, $supply->confirm($job)->getStatus());

        // No address at all: nothing to send to.
        $other = $this->paidOrder($this->user('host'), [[$card, 1]]);
        [$lost] = $supply->dispatch($other);
        self::assertSame(SupplyStatus::FAILED, $lost->getStatus());
        self::assertStringContainsString('recipient', (string) $lost->getMessage());
    }

    public function testTheWorkshopGetsABriefAndASignedLink(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function ($email) use (&$sent): void { $sent[] = $email; });
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p) => 'https://shop.test/marketplace/fabrication/'.$p['reference']);
        $signer = new UriSigner('secret');
        $workshop = new OfflineSupplier($this->entityManager, $urls, $signer, $mailer, 'atelier@example.org', 'Atelier Dupont');
        self::assertTrue($workshop->isConfigured());
        self::assertFalse((new OfflineSupplier($this->entityManager, $urls, $signer, $mailer))->isConfigured());

        $supply = new Supply($this->entityManager, new SupplierRegistry([$workshop]));
        $card = $this->goods('menu')->setSupplier('offline')->setSupplierReference('menu-a5-vergé');
        $order = $this->paidOrder($this->user('host'), [[$card, 120]]);
        $order->getItems()->first()->setRecipient(self::LEA);
        $this->entityManager->flush();

        [$job] = $supply->dispatch($order);
        self::assertSame(SupplyStatus::SUBMITTED, $job->getStatus());
        self::assertSame($job->getReference(), $job->getSupplierReference());
        self::assertCount(1, $sent);
        self::assertInstanceOf(TemplatedEmail::class, $sent[0]);
        self::assertSame('atelier@example.org', $sent[0]->getTo()[0]->getAddress());
        $context = $sent[0]->getContext();
        self::assertSame('menu-a5-vergé', $context['supply']->lines[0]->productReference);
        self::assertTrue($signer->checkRequest(Request::create($context['link'])), 'the link is the workshop\'s key');
        self::assertFalse($signer->checkRequest(Request::create(str_replace($job->getReference(), 'OTHER-1', $context['link']))));

        // The workshop answers on its page.
        $supply->apply(new SupplyResult('offline', $job->getReference(), SupplyStatus::SHIPPED, 'CP123', null, 'Chronopost', null, $job->getReference()));
        self::assertSame(SupplyStatus::SHIPPED, $workshop->fetch($job->getReference())->status);
        self::assertSame('CP123', $workshop->fetch($job->getReference())->trackingNumber);
    }
}
