<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Base\Marketplace\Twig\InvoiceTwigExtension;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Email\EmailGatewayFactory;
use Omnibill\Email\StatusStoreInterface;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidNotificationException;
use Omnibill\Exception\OmnibillException;
use Omnibill\GatewayInterface;
use Omnibill\Model\Flow;
use Omnibill\Model\Invoice as BillInvoice;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\Party;
use Omnibill\Model\StatusChange;
use Omnibill\Model\Syntax;
use Omnibill\Registry;
use Omnibill\Request\FetchStatus;
use Omnibill\Request\Notify;
use Omnibill\Request\SetStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shop's invoices through glitchr/omnibill: an invoice sent is
 * submitted to a gateway - omnibill/email by default, an approved platform
 * (omnibill/afnor) the day the shop must issue through one -, and where it
 * stands there (sent, deposited, refused, paid...) is kept beside it
 * (Entity\InvoiceFlow).
 *
 *     marketplace:
 *         invoice:
 *             gateway: email        # an omnibill gateway's name (omnibill.gateways.<name>); null: the bundle's own e-mail
 *
 * "email" needs no configuration: with omnibill/email installed and no
 * omnibill gateway of that name, the invoice goes from the seller's address
 * (marketplace.invoice.seller.email). This folder is registered, and its
 * entity mapped, only when glitchr/omnibill is installed: without it,
 * Service\Invoices sends its own e-mail and nothing here exists. What
 * omnibill throws leaves as a TransmissionException (a LogicException).
 */
class InvoiceTransmission
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?Registry $registry = null,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?StatusStoreInterface $store = null,
        #[Autowire('%marketplace.invoice.gateway%')] private readonly ?string $gateway = 'email',
    ) {
    }

    /** Whether invoices go through a gateway: one is named, and it can be built. */
    public function isEnabled(): bool
    {
        if (null === $this->gateway || '' === $this->gateway) {
            return false;
        }

        return ($this->registry?->has($this->gateway) ?? false) || ('email' === $this->gateway && $this->canMail());
    }

    public function name(): ?string
    {
        return $this->isEnabled() ? $this->gateway : null;
    }

    /** Where the invoice stands on the gateway, if it went through one. */
    public function flowOf(Invoice $invoice): ?InvoiceFlow
    {
        return null === $invoice->getId() ? null : $this->entityManager->getRepository(InvoiceFlow::class)->findOneBy(['invoice' => $invoice]);
    }

    /** Its latest lifecycle status, in words ("Encaissée"), with the gateway; null when it went through none. */
    public function describe(Invoice $invoice): ?string
    {
        $flow = $this->flowOf($invoice);
        if (null === $flow || null === $flow->getStatus()) {
            return null;
        }

        return ($this->translator?->trans('@marketplace.invoice.lifecycle.'.$flow->getStatus()) ?? $flow->getStatus()).' ('.$flow->getGateway().')';
    }

    /**
     * The invoice, its PDF (Factur-X) given, submitted to the gateway: what it
     * answers is kept beside the invoice, and an invoice handed over is sent.
     *
     * @param string $pdf  the invoice's PDF: Service\Invoices::pdf()
     * @param string $link the invoice's signed address, for the e-mail's text
     */
    public function submit(Invoice $invoice, string $pdf, string $filename, ?string $link = null, ?string $to = null): InvoiceFlow
    {
        try {
            $flow = $this->gatewayFor($invoice, $link)->submit($this->bill($invoice, $pdf, $filename, $to), $invoice->getNumber());
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        $row = $this->flowOf($invoice);
        if (null === $row) {
            $row = new InvoiceFlow($invoice, (string) $this->gateway, $flow->reference);
            $this->entityManager->persist($row);
        } else {
            $row->restart((string) $this->gateway, $flow->reference);
        }
        $this->reflect($row, $flow);
        $this->entityManager->flush();

        return $row;
    }

    /** Asks the gateway where the invoice stands, and keeps it; null when it went through none. */
    public function refresh(Invoice $invoice): ?InvoiceFlow
    {
        $row = $this->flowOf($invoice);
        if (null === $row || !$this->isEnabled()) {
            return null;
        }
        try {
            $gateway = $this->gatewayFor($invoice);
            if (!$gateway->supports(FetchStatus::class)) {
                return $row;
            }
            $this->reflect($row, $gateway->fetchStatus($this->flow($row)));
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        $this->entityManager->flush();

        return $row;
    }

    /** "Encaissée" (212), a status the reform makes mandatory: declared to the gateway, kept beside the invoice. */
    public function paid(Invoice $invoice, \DateTimeImmutable $at): void
    {
        $this->declare($invoice, LifecycleStatus::PAID, $at);
    }

    /** Declares a status of one's own invoice to the gateway when it takes it; kept beside the invoice either way. */
    public function declare(Invoice $invoice, LifecycleStatus $status, ?\DateTimeImmutable $at = null, ?string $reason = null): void
    {
        $row = $this->flowOf($invoice);
        if (null === $row || !$this->isEnabled()) {
            return;
        }
        $change = new StatusChange($status, $invoice->getNumber(), $at ?? new \DateTimeImmutable(), $reason, LifecycleStatus::PAID === $status ? self::decimal($invoice->getTotal(), $invoice->getCurrency()) : null);
        try {
            $gateway = $this->gatewayFor($invoice);
            if ($gateway->supports(SetStatus::class)) {
                $this->reflect($row, $gateway->setStatus($change, $this->flow($row)));
                $this->entityManager->flush();

                return;
            }
        } catch (InvalidConfigException) {
            // A platform that carries statuses as lifecycle files (CDAR) wants one: kept here only.
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        $row->setHistory([...$row->getHistory(), self::row($change)]);
        $this->entityManager->flush();
    }

    public function supportsNotify(string $gateway): bool
    {
        return ($this->registry?->has($gateway) ?? false) && $this->registry->get($gateway)->supports(Notify::class);
    }

    /**
     * A gateway's callback (an approved platform's webhook): its signature
     * checked by the gateway, the invoice it is about updated.
     *
     * @param array<string, string|list<string>> $headers
     *
     * @return Invoice|false|null the invoice updated; null when it names none of the shop's; false when its signature does not hold
     */
    public function notify(string $gateway, string $body, array $headers): Invoice|false|null
    {
        if (!$this->supportsNotify($gateway)) {
            return null;
        }
        try {
            $flow = $this->registry->get($gateway)->notify($body, $headers)->flow;
        } catch (InvalidNotificationException) {
            return false;
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        if (null === $flow) {
            return null;
        }
        $row = $this->entityManager->getRepository(InvoiceFlow::class)->findOneBy(['reference' => $flow->reference]);
        if (null === $row && null !== $flow->invoiceNumber && null !== ($invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['number' => $flow->invoiceNumber]))) {
            $row = $this->flowOf($invoice);
        }
        if (null === $row) {
            return null;
        }
        $this->reflect($row, $flow);
        $this->entityManager->flush();

        return $row->getInvoice();
    }

    /** What the gateway said, beside the invoice; the invoice sent once handed over, paid once paid. */
    private function reflect(InvoiceFlow $row, Flow $flow): void
    {
        $invoice = $row->getInvoice();
        $history = array_map(self::row(...), $flow->history);
        if ([] === $history && null !== $flow->status) {
            $history = [self::row(new StatusChange($flow->status, $invoice->getNumber(), $flow->updatedAt))];
        }
        if ([] !== $history) {
            $row->setHistory($history);
        }
        $status = null !== $row->getStatus() ? LifecycleStatus::tryFrom($row->getStatus()) : null;
        if (null !== $status && !\in_array($status, [LifecycleStatus::REJECTED, LifecycleStatus::REFUSED], true)) {
            $invoice->markSent($flow->submittedAt ?? new \DateTimeImmutable());
        }
        if (LifecycleStatus::PAID === $status && !$invoice->isPaid() && !$invoice->isCancelled()) {
            $last = $row->getHistory()[array_key_last($row->getHistory())];
            $invoice->markPaid(null !== $last['at'] ? new \DateTimeImmutable($last['at']) : new \DateTimeImmutable());
        }
    }

    /** @return array{status: string, code: int|null, at: string|null, reason: string|null} */
    public static function row(StatusChange $change): array
    {
        return ['status' => $change->status->value, 'code' => $change->status->code(), 'at' => $change->at?->format(\DATE_ATOM), 'reason' => $change->reason];
    }

    private function flow(InvoiceFlow $row): Flow
    {
        $number = $row->getInvoice()->getNumber();

        return new Flow($row->getReference(), trackingId: $number, invoiceNumber: $number, status: null !== $row->getStatus() ? LifecycleStatus::tryFrom($row->getStatus()) : null);
    }

    private function gatewayFor(Invoice $invoice, ?string $link = null): GatewayInterface
    {
        $name = (string) $this->gateway;
        if ($this->registry?->has($name)) {
            $gateway = $this->registry->get($name);
            if ('email' !== $gateway->getName()) {
                return $gateway;
            }

            // omnibill/email configured by the site: the bundle's words, where the site wrote none.
            return $this->registry->create($name, array_diff_key($this->message($invoice, $link), $this->registry->options($name)));
        }
        if ('email' !== $name || !$this->canMail()) {
            throw new InvalidConfigException(sprintf('No "%s" omnibill gateway (omnibill.gateways.%1$s).', $name));
        }
        $from = $invoice->getSeller()['email'] ?? null;
        if (null === $from || '' === $from) {
            throw new InvalidConfigException('Sending an invoice by omnibill/email needs the seller\'s address: marketplace.invoice.seller.email, or an omnibill "email" gateway with its "from".');
        }

        return (new EmailGatewayFactory($this->mailer, $this->store))->create(['from' => $from] + $this->message($invoice, $link));
    }

    private function canMail(): bool
    {
        return null !== $this->mailer && class_exists(EmailGatewayFactory::class);
    }

    /** @return array{subject: string, body: string} the bundle's own words for the e-mail */
    private function message(Invoice $invoice, ?string $link): array
    {
        $t = fn (string $key, array $parameters = []): string => $this->translator?->trans($key, $parameters) ?? $key;
        $subject = $t($invoice->isCreditNote() ? '@marketplace.invoice.mail.credit_subject' : '@marketplace.invoice.mail.subject', ['{number}' => $invoice->getNumber()]);
        $body = $t('@marketplace.invoice.mail.hello', ['{name}' => $invoice->getBuyer()['name'] ?? ''])."\n\n"
            .$t($invoice->isCreditNote() ? '@marketplace.invoice.mail.credit_text' : '@marketplace.invoice.mail.text', ['{number}' => $invoice->getNumber(), '{total}' => (new InvoiceTwigExtension())->money($invoice->getTotal(), $invoice->getCurrency()), '{seller}' => $invoice->getSeller()['name'] ?? ''])
            .(null !== $link ? "\n\n".$t('@marketplace.invoice.mail.action').' : '.$link : '')
            ."\n\n".($invoice->getSeller()['name'] ?? '');

        return ['subject' => $subject, 'body' => $body];
    }

    private function bill(Invoice $invoice, string $pdf, string $filename, ?string $to): BillInvoice
    {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();

        return new BillInvoice(
            $pdf,
            $filename,
            Syntax::FACTURX,
            $invoice->getNumber(),
            new Party($seller['name'] ?? null, $seller['siren'] ?? null, $seller['siret'] ?? null, email: $seller['email'] ?? null, country: $seller['country'] ?? 'FR'),
            new Party($buyer['name'] ?? null, $buyer['siren'] ?? null, $buyer['siret'] ?? null, email: $to ?? $buyer['email'] ?? null, country: $buyer['country'] ?? 'FR'),
            $invoice->getIssuedAt(),
            self::decimal($invoice->getTotal(), $invoice->getCurrency()),
            $invoice->getCurrency(),
        );
    }

    /** An amount in the currency's smallest unit, as a decimal string: "1234.50". */
    private static function decimal(int $amount, string $currency): string
    {
        $digits = Currencies::getFractionDigits($currency);

        return number_format($amount / 10 ** $digits, $digits, '.', '');
    }
}
