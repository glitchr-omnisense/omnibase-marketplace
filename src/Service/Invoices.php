<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Invoice\FacturX;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Invoice\InvoiceNumbering;
use Base\Marketplace\Repository\InvoiceRepository;
use Base\Response\PdfResponse;
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
     * The credit note that cancels an invoice, whole: its lines and amounts,
     * its seller and buyer as the invoice has them, its own number in the
     * credit notes' series; the invoice is cancelled by it.
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
        $mentions = $order ? $this->composer->mentions($order, $invoice->getBuyer(), $invoice->getTotals(), $at, null, $invoice, $reason) : array_filter([$reason]);
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

    public function markPaid(Invoice $invoice, ?\DateTimeImmutable $at = null): void
    {
        $invoice->markPaid($at ?? new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /** The invoice's page as the PDF prints it (HTML). */
    public function html(Invoice $invoice): string
    {
        return $this->twig->render('@Marketplace/invoice/invoice.pdf.twig', ['invoice' => $invoice]);
    }

    /** The invoice as a PDF, its Factur-X XML attached when horstoeko/zugferd is installed. */
    public function pdf(Invoice $invoice): string
    {
        $pdf = PdfResponse::render($this->html($invoice));

        return FacturX::available() ? $this->facturX->attach($invoice, $pdf) : $pdf;
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

    /** Sent to its buyer by e-mail, the PDF attached: marked sent. */
    public function send(Invoice $invoice, ?string $to = null): void
    {
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
