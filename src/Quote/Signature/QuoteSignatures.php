<?php

namespace Base\Marketplace\Quote\Signature;

use Base\Entity\Signature\Envelope;
use Base\Entity\User;
use Base\Event\SignatureEvent;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Pricing\ExportExemption;
use Base\Marketplace\Service\QuoteStatusGuard;
use Base\Marketplace\Service\QuoteToOrder;
use Base\Response\PdfResponse;
use Base\Service\Signatures;
use Doctrine\ORM\EntityManagerInterface;
use Omnisign\Model\Document;
use Omnisign\Model\Envelope as SignatureRequest;
use Omnisign\Model\Field;
use Omnisign\Model\File;
use Omnisign\Model\Signer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Twig\Environment;

/**
 * A priced quote accepted by signing it in the site's page, through
 * glitchr/omnisign (glitchr/omnibase's Base\Service\Signatures): its PDF
 * (@Marketplace/quote/quote.pdf.twig) sent as an envelope about the quote,
 * the client signing where they are, the quote accepted - its order, its
 * payment, as an acceptance without a signature - once the provider says it
 * is completed (the way back, or its webhook: SignatureEvent::COMPLETED).
 * Declined or expired, the quote stays open. The signed PDF and its evidence
 * stay with the envelope, in the private storage.
 *
 *     marketplace:
 *         quotes:
 *             signature: contracts            # a gateway of omnisign.gateways; absent (null): accepting is a click
 *             signature_template: ~           # DocuSeal's open-source edition signs its own templates only: one's id
 *
 * This folder is registered only when glitchr/omnisign and the core's
 * Signatures are there; nothing of it implements an omnisign interface.
 */
#[AsEventListener(event: SignatureEvent::COMPLETED, method: 'onCompleted')]
class QuoteSignatures
{
    /** The signature's box on the quote's last page, in points from its top left corner (A4: 595 × 842). */
    public const BOX = ['x' => 354, 'y' => 700, 'width' => 198, 'height' => 56];

    /** @param array<string, mixed> $seller marketplace.invoice.seller: who the quote is from */
    public function __construct(
        private readonly Signatures $signatures,
        private readonly QuoteToOrder $quoteToOrder,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%marketplace.quotes.signature%')] private readonly ?string $gateway = null,
        #[Autowire('%marketplace.quotes.signature_template%')] private readonly ?string $template = null,
        #[Autowire('%marketplace.invoice.seller%')] private readonly array $seller = [],
    ) {
    }

    /** Whether accepting a quote asks for a signature: a gateway named, and configured. */
    public function isEnabled(): bool
    {
        return null !== $this->gateway && '' !== $this->gateway && $this->signatures->isEnabled($this->gateway);
    }

    /** The quote's latest envelope, if it was sent to be signed. */
    public function latest(Quote $quote): ?Envelope
    {
        return null === $quote->getId() ? null : ($this->signatures->of($quote)[0] ?? null);
    }

    /** The quote as a PDF: what is signed. */
    public function pdf(Quote $quote): string
    {
        return PdfResponse::render($this->twig->render('@Marketplace/quote/quote.pdf.twig', ['quote' => $quote, 'seller' => $this->seller, 'box' => self::BOX, 'export' => null !== $quote->getCountry() && !ExportExemption::inEu($quote->getCountry())]));
    }

    /**
     * Where the client signs the quote, in the site's page: its envelope
     * sent once, asked again while it waits for its signature.
     */
    public function start(Quote $quote, string $returnUrl): string
    {
        if (!$quote->isAcceptable()) {
            throw new \DomainException('quote.error.not_acceptable');
        }
        $envelope = $this->latest($quote);
        if (null === $envelope || $envelope->isOver()) {
            $envelope = $this->signatures->send($quote, $this->request($quote, $returnUrl), $this->gateway);
        }

        return $this->signatures->signingUrl($envelope, 'client', $returnUrl);
    }

    /**
     * Back from signing: where the envelope stands, asked of the provider;
     * the quote accepted for $client once it is completed.
     *
     * @return array{status: string, order: ?Order} the envelope's status (sent, completed, declined, expired, canceled), the order once accepted
     */
    public function back(Quote $quote, User $client): array
    {
        $envelope = $this->latest($quote) ?? throw new \DomainException('quote.error.not_acceptable');
        $this->signatures->refresh($envelope);
        $order = null;
        if ($envelope->isCompleted() && ($quote->isAcceptable() || QuoteStatusGuard::isAwaitingPayment($quote))) {
            $order = $this->quoteToOrder->accept($quote, $client);
        }

        return ['status' => $envelope->getStatus(), 'order' => $order];
    }

    /** Completed, the way back or the provider's webhook: the quote accepted, as a click accepted it. */
    public function onCompleted(SignatureEvent $event): void
    {
        $quote = $event->getSubject();
        if (!$quote instanceof Quote || !$quote->isAcceptable()) {
            return;
        }
        $client = $quote->getClient() ?? (class_exists('App\\Entity\\User') ? $this->entityManager->getRepository('App\\Entity\\User')->findOneBy(['email' => $quote->getEmail()]) : null);
        if (!$client instanceof User) {
            // Nobody to make the order for yet: the client's way back does it.
            $this->logger?->info('Quote {reference} signed; accepted when its client comes back.', ['reference' => $quote->getReference()]);

            return;
        }
        $this->quoteToOrder->accept($quote, $client);
    }

    /** The signed quote, or its evidence ("evidence"), once completed. */
    public function file(Quote $quote, string $kind = 'document'): ?string
    {
        $envelope = $this->latest($quote);
        if (null === $envelope || !$envelope->isCompleted()) {
            return null;
        }

        return 'evidence' === $kind ? $this->signatures->evidence($envelope) : $this->signatures->signed($envelope);
    }

    private function request(Quote $quote, string $returnUrl): SignatureRequest
    {
        $title = sprintf('%s · %s', $quote->getReference(), $quote->getTitle());
        $signer = new Signer('client', $quote->getContactName() ?: $quote->getEmail(), $quote->getEmail());
        if (null !== $this->template && '' !== $this->template) {
            // A template of the provider's (DocuSeal's open-source edition): the quote is what its page shows.
            return new SignatureRequest($title, signers: [$signer], embedded: true, redirectUrl: $returnUrl, template: $this->template, key: $quote->getReference());
        }
        $pdf = $this->pdf($quote);

        return new SignatureRequest(
            $title,
            [new Document('quote', new File($pdf, sprintf('cotation-%s.pdf', $quote->getReference())))],
            [$signer],
            [new Field('client', 'quote', page: self::pages($pdf), x: self::BOX['x'], y: self::BOX['y'], width: self::BOX['width'], height: self::BOX['height'])],
            embedded: true,
            redirectUrl: $returnUrl,
            key: $quote->getReference(),
        );
    }

    /** The PDF's pages: the signature goes on the last. */
    private static function pages(string $pdf): int
    {
        return max(1, (int) preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf));
    }
}
