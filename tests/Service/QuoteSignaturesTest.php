<?php

namespace Tests\Base\Marketplace\Service;

use Base\Entity\Signature\Envelope;
use Base\Event\SignatureEvent;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Quote\Signature\QuoteSignatures;
use Base\Marketplace\Repository\QuoteRepository;
use Base\Marketplace\Service\QuoteToOrder;
use Base\Service\Signatures;
use Omnisign\Docuseal\DocusealGatewayFactory;
use Omnisign\Registry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A priced quote accepted by signing it in the page (glitchr/omnisign,
 * marketplace.quotes.signature): its PDF sent to be signed, nothing
 * accepted before the signature is completed, then the quote accepted as a
 * click accepts it - its order made -, the signed PDF and its evidence
 * downloadable; declined, the quote stays open; without the setting,
 * nothing asks for a signature. DocuSeal answers as omnisign/docuseal's
 * recorded answers do (an instance of its open-source edition; its Pro
 * edition's /submissions/pdf answered alike). Skipped without
 * glitchr/omnisign, omnisign/docuseal and the core's Signatures.
 */
final class QuoteSignaturesTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    /** @var list<array{string, string, string}> */
    private array $calls = [];

    private Signatures $signatures;

    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(DocusealGatewayFactory::class) || !class_exists(Signatures::class)) {
            self::markTestSkipped('Needs glitchr/omnisign, omnisign/docuseal and glitchr/omnibase\'s Signatures.');
        }
        parent::setUp();
        if (!class_exists(\Dompdf\Dompdf::class)) {
            self::markTestSkipped('Needs dompdf/dompdf.');
        }
    }

    /** @param list<string|MockResponse> $fetches what DocuSeal answers of the submission, in turn (the last one stays) */
    private function quoteSignatures(array $fetches, ?string $gateway = 'contracts', ?EventDispatcher $dispatcher = null): QuoteSignatures
    {
        $fixtures = \dirname((string) (new \ReflectionClass(DocusealGatewayFactory::class))->getFileName()).'/Tests/Fixtures/';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$fetches, $fixtures): MockResponse {
            $this->calls[] = [$method, $url, (string) ($options['body'] ?? '')];
            if (str_contains($url, '/file/')) {
                return new MockResponse(str_contains($url, 'Audit%20Log') ? '%PDF-1.6 audit' : '%PDF-1.4 signed');
            }
            $answer = 'POST' === $method ? 'submissions-create' : (\count($fetches) > 1 ? array_shift($fetches) : $fetches[0]);

            // Recorded for a lease's tenant: the quote's signer is its client.
            return $answer instanceof MockResponse ? $answer : new MockResponse(str_replace('"tenant"', '"client"', (string) file_get_contents($fixtures.$answer.'.json')));
        });
        $registry = new Registry([new DocusealGatewayFactory($http)], ['contracts' => ['factory' => 'docuseal', 'options' => ['url' => 'http://localhost:13300/api', 'api_key' => 'k']]]);
        $container = self::getContainer();
        $dispatcher ??= new EventDispatcher();
        $this->signatures = $signatures = new Signatures($this->entityManager, $container->get('flysystem'), $registry, $dispatcher, uploads: $container->getParameter('base.uploader.storage'));
        $quoteSignatures = new QuoteSignatures($signatures, $container->get(QuoteToOrder::class), $this->entityManager, $container->get('twig'), null, $gateway, null, (array) $container->getParameter('marketplace.invoice.seller'));
        $dispatcher->addListener(SignatureEvent::COMPLETED, $quoteSignatures->onCompleted(...));

        return $quoteSignatures;
    }

    private function quote(bool $client = true): Quote
    {
        $user = $this->user('client');
        $quote = (new Quote())->setTitle('Vitrophanie de la boutique')->setEmail($user->getEmail())->setContactName('Camille Érable')
            ->setStore($this->store())->setClient($client ? $user : null);
        $quote->addLine(new QuoteLine($this->goods('adhesif', 1900), 3, 5700, 1, 'Adhésif découpé'));
        $quote->setStatus(QuoteStatus::SENT);
        self::getContainer()->get(QuoteRepository::class)->saveNumbered($quote);

        return $quote;
    }

    public function testThePdfIsSignedInThePageThenTheQuoteAcceptedAndItsFilesKept(): void
    {
        // Sending asks the submission once (pending), then each way back.
        $quoteSignatures = $this->quoteSignatures(['submission-pending', 'submission-pending', 'submission-completed']);
        $quote = $this->quote();
        self::assertTrue($quoteSignatures->isEnabled());

        $url = $quoteSignatures->start($quote, 'https://shop.example/cotation/x/signee');
        self::assertMatchesRegularExpression('~^http://localhost:13300/s/\w+$~', $url, 'the provider\'s signing page');
        [$method, $endpoint, $body] = $this->calls[0];
        self::assertSame(['POST', 'http://localhost:13300/api/submissions/pdf'], [$method, $endpoint], 'the quote\'s own PDF');
        $sent = json_decode($body, true);
        self::assertStringStartsWith('%PDF-', base64_decode($sent['documents'][0]['file']));
        self::assertSame(['x' => 354, 'y' => 700, 'w' => 198, 'h' => 56, 'page' => 1], $sent['documents'][0]['fields'][0]['areas'][0], 'its box, on the last page');
        self::assertSame('https://shop.example/cotation/x/signee', $sent['completed_redirect_url']);
        self::assertSame(QuoteStatus::SENT, $quote->getStatus(), 'nothing accepted before the signature');
        self::assertSame($url, $quoteSignatures->start($quote, 'https://shop.example/cotation/x/signee'), 'the same envelope while it waits');
        self::assertCount(1, array_filter($this->calls, static fn ($c) => 'POST' === $c[0]));

        $back = $quoteSignatures->back($quote, $quote->getClient());
        self::assertSame(['sent', null], [$back['status'], $back['order']], 'not signed yet');
        self::assertNull($quoteSignatures->file($quote));

        $back = $quoteSignatures->back($quote, $quote->getClient());
        self::assertSame('completed', $back['status']);
        self::assertNotNull($back['order'], 'accepted as a click accepts it: its order made');
        self::assertSame(QuoteStatus::ACCEPTED, $quote->getStatus());
        self::assertSame($back['order'], $quote->getOrder());
        self::assertSame('%PDF-1.4 signed', $quoteSignatures->file($quote));
        self::assertSame('%PDF-1.6 audit', $quoteSignatures->file($quote, 'evidence'));
        self::assertSame(Quote::class, $quoteSignatures->latest($quote)->getSubjectClass(), 'the envelope is about the quote');
    }

    public function testTheProvidersWordAcceptsItWithoutTheClientComingBack(): void
    {
        $quoteSignatures = $this->quoteSignatures(['submission-pending', 'submission-completed']);
        $quote = $this->quote();
        $quoteSignatures->start($quote, 'https://shop.example/r');
        self::assertSame(QuoteStatus::SENT, $quote->getStatus());

        // What the webhook does (Signatures::notify(), then refresh()): COMPLETED dispatched, the quote accepted for its client.
        $this->signatures->refresh($quoteSignatures->latest($quote));
        self::assertSame(QuoteStatus::ACCEPTED, $quote->getStatus());
        self::assertNotNull($quote->getOrder());
    }

    public function testDeclinedTheQuoteStaysOpenAndMaySignAgain(): void
    {
        $fixtures = \dirname((string) (new \ReflectionClass(DocusealGatewayFactory::class))->getFileName()).'/Tests/Fixtures/';
        $declined = json_decode(str_replace('"tenant"', '"client"', (string) file_get_contents($fixtures.'submission-pending.json')), true);
        $declined['status'] = 'declined';
        foreach ($declined['submitters'] as &$submitter) {
            $submitter['status'] = 'declined';
        }
        $quoteSignatures = $this->quoteSignatures(['submission-pending', new MockResponse(json_encode($declined))]);
        $quote = $this->quote();
        $quoteSignatures->start($quote, 'https://shop.example/r');

        $back = $quoteSignatures->back($quote, $quote->getClient());
        self::assertSame(['declined', null], [$back['status'], $back['order']]);
        self::assertSame(QuoteStatus::SENT, $quote->getStatus(), 'still open');
        self::assertTrue($quote->isAcceptable());
        self::assertSame(Envelope::STATUS_DECLINED, $quoteSignatures->latest($quote)->getStatus());

        $quoteSignatures->start($quote, 'https://shop.example/r');
        self::assertCount(2, array_filter($this->calls, static fn ($c) => 'POST' === $c[0]), 'a new envelope: the one declined is over');
    }

    public function testWithoutTheSettingNothingAsksForASignature(): void
    {
        self::assertFalse($this->quoteSignatures(['submission-pending'], null)->isEnabled(), 'marketplace.quotes.signature absent');
        self::assertFalse($this->quoteSignatures(['submission-pending'], 'nowhere')->isEnabled(), 'a gateway not configured');
        self::assertFalse(self::getContainer()->get(QuoteSignatures::class)->isEnabled(), 'the harness sets none: accepting is a click');
    }
}
