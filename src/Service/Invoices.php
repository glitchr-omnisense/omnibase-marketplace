<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Invoice\InvoiceNumbering;
use Base\Marketplace\Model\InvoiceSource;
use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Base\Marketplace\Repository\InvoiceRepository;
use Omnibridge\Symfony\Omnibase\Response\PdfResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The shop's invoices: issued for a paid order (issue()), cancelled by a
 * credit note (credit()), sent by e-mail (send()), marked paid; and their
 * documents - the PDF (@Marketplace/invoice/invoice.pdf.twig, a site's
 * templates/bundles/MarketplaceBundle/invoice/invoice.pdf.twig over it)
 * carrying its Factur-X XML.
 *
 *     $invoice = $invoices->issue($order);          // F-2026-000041, frozen
 *     $pdf = $invoices->pdf($invoice);              // PDF/A-3 + factur-x.xml
 *     $creditNote = $invoices->credit($invoice, 'Retour du colis');
 *
 * With marketplace.invoice.auto_issue, an order's invoice is issued when it
 * is paid (EventListener\InvoiceOnPaymentListener).
 *
 * With omnisense/omnibill, send() goes through its gateway
 * (marketplace.invoice.gateway: omnibill/email by default, an approved
 * platform later) and the invoice keeps where it stands there
 * (Invoice\Transmission\InvoiceTransmission); without it, send() mails the
 * invoice itself.
 */
class Invoices
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceComposer $composer,
        private readonly InvoiceNumbering $numbering,
        private readonly FacturX $facturX,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly UriSigner $signer,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire('%marketplace.invoice.prefix%')] private readonly string $prefix = 'F',
        #[Autowire('%marketplace.invoice.credit_prefix%')] private readonly string $creditPrefix = 'A',
        #[Autowire('%marketplace.invoice.payment_days%')] private readonly int $paymentDays = 30,
        private readonly ?InvoiceTransmission $transmission = null,
    ) {
    }

    public function repository(): InvoiceRepository
    {
        return $this->entityManager->getRepository(Invoice::class);
    }

    /** The order's invoice that stands, if it has one. */
    public function of(Order $order): ?Invoice
    {
        return $this->repository()->standingFor($order);
    }

    /**
     * The invoice of an order: issued once, for an order paid or waiting for
     * its payment (a cart is not invoiced). An order whose invoice was
     * cancelled by a credit note may be invoiced again.
     *
     * @throws \LogicException for a cart, or an order that has its invoice
     */
    public function issue(Order $order, ?\DateTimeImmutable $at = null): Invoice
    {
        if (!$order->isPaid()) {
            throw new \LogicException(sprintf('The order %s is a cart: it is not invoiced.', $order->getReference()));
        }
        if ($existing = $this->of($order)) {
            throw new \LogicException(sprintf('The order %s has its invoice: %s.', $order->getReference(), $existing->getNumber()));
        }
        $at ??= new \DateTimeImmutable();
        $seller = $this->composer->seller($order);
        $buyer = $this->composer->buyer($order);
        ['lines' => $lines, 'totals' => $totals] = $this->composer->amounts($order);
        $soldAt = null !== $order->getPaidAt() ? \DateTimeImmutable::createFromInterface($order->getPaidAt()) : null;
        $dueAt = $totals['due'] > 0 ? $at->modify(sprintf('+%d days', $this->paymentDays)) : $at;
        $mentions = $this->composer->mentions($order, $buyer, $totals, $at, $dueAt);

        return $this->numbering->issue($this->prefix, $at, function (int $sequence, string $number, int $year) use ($order, $at, $seller, $buyer, $lines, $totals, $mentions, $soldAt, $dueAt) {
            $invoice = new Invoice(
                Invoice::TYPE_INVOICE, $this->prefix, $year, $sequence, $number, $at, $order, $order->getCurrency(),
                $seller, $buyer, $lines, $totals, $mentions, $soldAt, $dueAt,
            );
            // Already paid (the order's payment): an invoice "acquittée".
            if ($totals['due'] <= 0 && null !== $soldAt) {
                $invoice->markPaid($soldAt);
            }

            return $invoice;
        });
    }

    /**
     * What an invoice made without an order would say if issued now - its
     * seller, buyer, lines, totals, mentions and dates - for a person to
     * review it before issueFor().
     *
     * @return array{seller: array<string, mixed>, buyer: array<string, mixed>, lines: list<array<string, mixed>>, totals: array<string, mixed>, mentions: list<string>, sold_at: ?\DateTimeImmutable, due_at: \DateTimeImmutable}
     */
    public function compose(InvoiceSource $source, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable();
        $totals = $source->totals + ['paid' => 0, 'exemption' => null];
        $totals['due'] ??= ($totals['total'] ?? 0) - $totals['paid'];
        $dueAt = $totals['due'] > 0 ? $at->modify(sprintf('+%d days', $this->paymentDays)) : $at;

        return [
            'seller' => $this->composer->sellerFor($source->store, $source->sellerName),
            'buyer' => $source->buyer,
            'lines' => $source->lines,
            'totals' => $totals,
            'mentions' => $this->composer->mentionsFor($source->buyer, $totals, $dueAt, $source->operation, $source->mentions, $source->paidAt),
            'sold_at' => $source->soldAt,
            'due_at' => $dueAt,
        ];
    }

    /**
     * An invoice made without an order (InvoiceSource: an invoice of hours, of
     * a subscription), issued as issue() issues an order's: numbered in the
     * same series without a gap, its seller and mentions written here, frozen.
     * $issued is told the invoice inside the numbering's transaction, before
     * it is stored - to mark what it bills, in the same flush.
     *
     * @param (\Closure(Invoice): void)|null $issued
     *
     * @throws \LogicException an invoice that comes to nothing
     */
    public function issueFor(InvoiceSource $source, ?\DateTimeImmutable $at = null, ?\Closure $issued = null): Invoice
    {
        $at ??= new \DateTimeImmutable();
        $invoice = $this->compose($source, $at);
        if ([] === $invoice['lines'] || ($invoice['totals']['total'] ?? 0) <= 0) {
            throw new \LogicException('Nothing to invoice: the invoice has no line, or comes to nothing.');
        }

        return $this->numbering->issue($this->prefix, $at, function (int $sequence, string $number, int $year) use ($source, $invoice, $at, $issued) {
            $document = new Invoice(
                Invoice::TYPE_INVOICE, $this->prefix, $year, $sequence, $number, $at, null, $source->currency,
                $invoice['seller'], $invoice['buyer'], $invoice['lines'], $invoice['totals'], $invoice['mentions'], $invoice['sold_at'], $invoice['due_at'],
            );
            if ($invoice['totals']['due'] <= 0 && null !== $source->paidAt) {
                $document->markPaid($source->paidAt);
            }
            if (null !== $issued) {
                $issued($document);
            }

            return $document;
        });
    }

    /**
     * The credit note that cancels an invoice, whole: its lines and amounts,
     * its seller and buyer as the invoice has them, its own number in the
     * credit notes' series; the invoice is cancelled by it. Its mentions name
     * the invoice it cancels ("Avoir annulant la facture F-2026-000042 du
     * 02/11/2026."), then the reason, the exemption and the nature of the
     * operations the invoice stated - an invoice with an order or without.
     */
    public function credit(Invoice $invoice, ?string $reason = null, ?\DateTimeImmutable $at = null): Invoice
    {
        if ($invoice->isCreditNote()) {
            throw new \LogicException('A credit note is not credited.');
        }
        if ($invoice->isCancelled()) {
            throw new \LogicException(sprintf('The invoice %s is already cancelled by %s.', $invoice->getNumber(), $invoice->getCreditNote()?->getNumber()));
        }
        $at ??= new \DateTimeImmutable();
        $order = $invoice->getOrder();
        // Without an order (issueFor(), or its order deleted since): the same mentions, written from the invoice itself.
        $mentions = $order
            ? $this->composer->mentions($order, $invoice->getBuyer(), $invoice->getTotals(), $at, null, $invoice, $reason)
            : $this->composer->mentionsFor($invoice->getBuyer(), $invoice->getTotals(), null, $this->composer->operationOf($invoice), credited: $invoice, reason: $reason);
        $totals = ['paid' => 0, 'due' => 0] + $invoice->getTotals();
        $totals['paid'] = 0;
        $totals['due'] = $totals['total'];

        return $this->numbering->issue($this->creditPrefix, $at, function (int $sequence, string $number, int $year) use ($invoice, $at, $order, $mentions, $totals) {
            $creditNote = new Invoice(
                Invoice::TYPE_CREDIT_NOTE, $this->creditPrefix, $year, $sequence, $number, $at, $order, $invoice->getCurrency(),
                $invoice->getSeller(), $invoice->getBuyer(), $invoice->getLines(), $totals, array_values($mentions), $invoice->getSoldAt(), null, $invoice,
            );
            $invoice->cancelBy($creditNote, $at);

            return $creditNote;
        });
    }

    /**
     * An invoice rejected by the platform or refused by its buyer, corrected
     * the only way an issued invoice is: cancelled by a credit note, and the
     * order invoiced again - as it stands now (its customer's SIREN, its
     * address corrected). Neither is sent: the back office sends them.
     *
     * @return array{credit_note: Invoice, invoice: Invoice|null} the new invoice null when the invoice had no order left
     */
    public function reissue(Invoice $invoice, ?string $reason = null, ?\DateTimeImmutable $at = null): array
    {
        $creditNote = $this->credit($invoice, $reason, $at);
        $order = $invoice->getOrder();

        return ['credit_note' => $creditNote, 'invoice' => null !== $order ? $this->issue($order, $at) : null];
    }

    /** What the seller is to do about the invoice on its gateway (a rejection, a refusal, a status waiting), null when nothing. */
    public function guidance(Invoice $invoice): ?string
    {
        return $this->transmission?->guidance($invoice);
    }

    public function markPaid(Invoice $invoice, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $invoice->markPaid($at);
        $this->entityManager->flush();
        // Told to the gateway it went through: "Encaissée" (212), a status the reform makes mandatory.
        $this->transmission?->paid($invoice, $at);
    }

    /** The gateway invoices go through (omnisense/omnibill), null when they are mailed by this bundle. */
    public function gateway(): ?string
    {
        return $this->transmission?->name();
    }

    /** Where the invoice stands on that gateway, in words ("Encaissée (email)"); null when it went through none. */
    public function lifecycle(Invoice $invoice): ?string
    {
        return $this->transmission?->describe($invoice);
    }

    /** Its latest lifecycle status on its gateway (Omnibill\Model\LifecycleStatus's value), null when it went through none. */
    public function lifecycleStatus(Invoice $invoice): ?string
    {
        return $this->transmission?->flowOf($invoice)?->getStatus();
    }

    /** Whether it goes to an approved platform as an e-invoice (a company established in France): sent without the buyer's e-mail. */
    public function isEInvoice(Invoice $invoice): bool
    {
        $channel = $this->transmission?->channel($invoice);

        return null !== $channel && $this->transmission->isPlatform($channel);
    }

    /** Asks the gateway where the invoice stands; false when it went through none. */
    public function refresh(Invoice $invoice): bool
    {
        return null !== $this->transmission?->refresh($invoice);
    }

    /**
     * Whether the invoice's deposit waits for a person: its outcome unknown, and its gateway
     * cannot look it up, or its lookup answered nothing to rely on (confirmDeposit()).
     */
    public function awaitsConfirmation(Invoice $invoice): bool
    {
        return $this->transmission?->awaitsConfirmation($invoice) ?? false;
    }

    /**
     * A deposit whose outcome is unknown, settled by a person who checked on the platform:
     * there under the flow id it shows ($reference), kept as deposited; absent, deposited
     * again now, once (send()).
     *
     * @throws \LogicException nothing to settle, no flow id given, or the new deposit refused
     */
    public function confirmDeposit(Invoice $invoice, bool $deposited, ?string $reference = null): void
    {
        if (null === $this->transmission) {
            throw new \LogicException(sprintf('The invoice %s went through no gateway.', $invoice->getNumber()));
        }
        if (null === $this->transmission->confirmDeposit($invoice, $deposited, $reference)) {
            $this->send($invoice);
        }
    }

    /**
     * The statuses declared about the invoice whose outcome is unknown, by index: each waits
     * for a person (confirmStatus()).
     *
     * @return array<int, array{status: string, at: string, sent: bool, amount?: ?string, reason?: ?string, flow?: ?string, error?: ?string, unknown?: bool}>
     */
    public function unknownStatuses(Invoice $invoice): array
    {
        return $this->transmission?->flowOf($invoice)?->unknownDeclared() ?? [];
    }

    /** Why a status declared about the invoice has not gone: the gateway's answer kept on it. */
    public function pendingError(Invoice $invoice, int $index): ?string
    {
        return $this->transmission?->flowOf($invoice)?->getDeclared()[$index]['error'] ?? null;
    }

    /**
     * A status whose outcome was unknown, settled by a person who checked on the platform:
     * received ($taken), marked sent; absent, sent again now, once. Whether it went.
     */
    public function confirmStatus(Invoice $invoice, int $index, bool $taken): bool
    {
        return $this->transmission?->confirmStatus($invoice, $index, $taken) ?? false;
    }

    /** The invoice's page as the PDF prints it (HTML). */
    public function html(Invoice $invoice): string
    {
        return $this->twig->render('@Marketplace/invoice/invoice.pdf.twig', ['invoice' => $invoice]);
    }

    /**
     * The invoice as a PDF, its Factur-X XML attached when horstoeko/zugferd is installed.
     *
     * @param array<string, mixed> $routing what an approved platform routes it by (InvoiceTransmission::addressing())
     */
    public function pdf(Invoice $invoice, array $routing = []): string
    {
        $pdf = PdfResponse::render($this->html($invoice));

        return FacturX::available() ? $this->facturX->attach($invoice, $pdf, $routing) : $pdf;
    }

    public function xml(Invoice $invoice): string
    {
        return $this->facturX->xml($invoice);
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->getNumber().'.pdf';
    }

    /** The invoice's address for whoever holds the link (a mail, a page): signed, valid $ttl seconds. */
    public function signedUrl(Invoice $invoice, int $ttl = 2592000): string
    {
        $url = $this->router->generate('marketplace_invoice', ['number' => $invoice->getNumber()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateTimeImmutable(sprintf('+%d seconds', $ttl)));
    }

    /**
     * Sent to its buyer, the PDF attached: through the omnibill gateway when
     * there is one (its statuses then kept on the invoice), by this bundle's
     * own e-mail otherwise. Marked sent.
     */
    public function send(Invoice $invoice, ?string $to = null): void
    {
        if (null !== $this->transmission && null !== $this->transmission->channel($invoice)) {
            // To an approved platform: where the buyer receives, asked of its directory, written in the Factur-X.
            $routing = $this->transmission->addressing($invoice);
            $this->transmission->submit($invoice, $this->pdf($invoice, $routing), $this->filename($invoice), $this->signedUrl($invoice), $to, $routing);

            return;
        }
        $to ??= $invoice->getBuyer()['email'] ?? null;
        if (null === $this->mailer || null === $to) {
            throw new \LogicException(null === $to ? sprintf('The invoice %s has no address to go to.', $invoice->getNumber()) : 'Sending an invoice needs symfony/mailer.');
        }
        $subject = $this->translator?->trans($invoice->isCreditNote() ? '@marketplace.invoice.mail.credit_subject' : '@marketplace.invoice.mail.subject', ['{number}' => $invoice->getNumber()]) ?? $invoice->getNumber();
        $this->mailer->send((new TemplatedEmail())
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('@Marketplace/email/invoice.html.twig')
            ->context(['invoice' => $invoice, 'link' => $this->signedUrl($invoice)])
            ->addPart(new DataPart($this->pdf($invoice), $this->filename($invoice), 'application/pdf')));
        $invoice->markSent();
        $this->entityManager->flush();
    }
}
