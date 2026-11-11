<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Enum\InvoiceRoute;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceFlow;
use Base\Marketplace\Invoice\Transmission\Entity\InvoiceInbox;
use Base\Marketplace\Service\CompanyRegistry;
use Base\Marketplace\Twig\InvoiceTwigExtension;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Email\EmailGatewayFactory;
use Omnibill\Email\StatusStoreInterface;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\InvalidNotificationException;
use Omnibill\Exception\NotSupportedException;
use Omnibill\Exception\OmnibillException;
use Omnibill\Exception\OutcomeUnknownException;
use Omnibill\Exception\ProviderException;
use Omnibill\Exception\UnreachableException;
use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\FlowLookupInterface;
use Omnibill\GatewayInterface;
use Omnibill\Model\Addressing;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\Invoice as BillInvoice;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\Party;
use Omnibill\Model\StatusChange;
use Omnibill\Model\Syntax;
use Omnibill\Registry;
use Omnibill\Request\FetchStatus;
use Omnibill\Request\FindFlow;
use Omnibill\Request\Lookup;
use Omnibill\Request\Notify;
use Omnibill\Request\Receive;
use Omnibill\Request\SetStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shop's invoices through omnisense/omnibill: an invoice sent is
 * submitted to a gateway - omnibill/email by default, an approved platform
 * (omnibill/afnor) the day the shop must issue through one -, and where it
 * stands there (sent, deposited, rejected, refused, approved, paid...) is
 * kept beside it (Entity\InvoiceFlow).
 *
 *     marketplace:
 *         invoice:
 *             gateway: email        # an omnibill gateway's name (omnibill.gateways.<name>); null: the bundle's own e-mail
 *             email_gateway: email  # with a platform as "gateway": where the invoices that are no e-invoice go
 *
 * "email" needs no configuration: with omnibill/email installed and no
 * omnibill gateway of that name, the invoice goes from the seller's address
 * (marketplace.invoice.seller.email).
 *
 * With an approved platform (a gateway whose Capabilities say "approved"):
 *
 *   - only an e-invoice goes to it - a buyer that is a company established in
 *     France (Enum\InvoiceRoute::DOMESTIC_B2B) -, submitted once, routed to
 *     the electronic address the platform's directory gives for the buyer's
 *     SIREN (Lookup), under the processing rule B2B; the flow's reference,
 *     the rule, the address and the file's fingerprint are kept. A sale to a
 *     private person or to a company abroad is no e-invoice: it goes by the
 *     e-mail gateway, and is e-reported (EReporting);
 *   - its lifecycle follows from the platform: deposited or rejected (its
 *     reasons) by refresh() and the webhook, refused, approved or paid by the
 *     buyer from the lifecycle flows coming in (receiveStatuses(), the
 *     shop's own cursor);
 *   - the shop's own statuses ("Encaissée", 212) are declared as lifecycle
 *     messages (CDAR) the application writes (LifecycleMessageInterface):
 *     without a writer they stay pending on the invoice and are sent again
 *     by sendPending() (marketplace:invoice:status);
 *   - a submission whose answer was lost after it left (omnibill's
 *     OutcomeUnknownException: a timeout, a 5xx) is never sent again
 *     blindly: the platform is asked for the invoice's number (FindFlow, the
 *     trackingId it was given), at once and before any new submission; the
 *     invoice is deposited again only once the platform says it has none,
 *     SEARCH_DELAY after. A gateway that cannot look it up, or a lookup
 *     whose answer is none to rely on, leaves it to a person, who checks on
 *     the platform and answers (confirmDeposit()): there, under its flow id;
 *     absent, deposited again once. A status whose answer was lost is not
 *     sent again by itself: a person checks on the platform and answers
 *     (confirmStatus()): received, or absent and sent again once.
 *
 * This folder is registered, and its entities mapped, only when
 * omnisense/omnibill is installed: without it, Service\Invoices sends its
 * own e-mail and nothing here exists. What omnibill throws leaves as a
 * TransmissionException (a LogicException).
 */
class InvoiceTransmission
{
    /** How long a flow just deposited may take to show in the platform's search: before, "none" proves nothing. */
    public const SEARCH_DELAY = 'PT10M';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?Registry $registry = null,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?StatusStoreInterface $store = null,
        #[Autowire('%marketplace.invoice.gateway%')] private readonly ?string $gateway = 'email',
        private readonly ?LifecycleMessageInterface $messages = null,
        private readonly ?CompanyRegistry $companies = null,
        #[Autowire('%marketplace.invoice.email_gateway%')] private readonly ?string $emailGateway = 'email',
        #[Autowire('%marketplace.invoice.operation%')] private readonly string $operation = 'goods',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** Whether invoices go through a gateway: one is named, and it can be built. */
    public function isEnabled(): bool
    {
        return $this->available($this->gateway);
    }

    public function name(): ?string
    {
        return $this->isEnabled() ? $this->gateway : null;
    }

    /** Whether that gateway (the configured one by default) is an approved platform's channel. */
    public function isPlatform(?string $name = null): bool
    {
        $name ??= $this->gateway;
        if (null === $name || !($this->registry?->has($name) ?? false)) {
            return false;
        }
        try {
            return $this->registry->get($name)->capabilities()->approved;
        } catch (OmnibillException) {
            return false;
        }
    }

    /** The reform's channel of the invoice: an e-invoice, or a sale e-reported; null for a seller outside France. */
    public function routeOf(Invoice $invoice): ?InvoiceRoute
    {
        return InvoiceRoute::of($invoice->getSeller(), $invoice->getBuyer());
    }

    /**
     * The gateway this invoice goes through: the configured one, unless it is
     * an approved platform and the invoice is no e-invoice - then the e-mail
     * gateway; null when none (the bundle's own e-mail).
     */
    public function channel(Invoice $invoice): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        if (!$this->isPlatform() || (bool) $this->routeOf($invoice)?->isEInvoice()) {
            return $this->gateway;
        }

        return $this->available($this->emailGateway) && !$this->isPlatform($this->emailGateway) ? $this->emailGateway : null;
    }

    /** Where the invoice stands on the gateway, if it went through one. */
    public function flowOf(Invoice $invoice): ?InvoiceFlow
    {
        return null === $invoice->getId() ? null : $this->entityManager->getRepository(InvoiceFlow::class)->findOneBy(['invoice' => $invoice]);
    }

    /** Its latest lifecycle status, in words ("Rejetée : ..."), with the gateway; null when it went through none. */
    public function describe(Invoice $invoice): ?string
    {
        $flow = $this->flowOf($invoice);
        if (null === $flow || null === $flow->getStatus()) {
            return null;
        }
        $reason = \in_array($flow->getStatus(), ['rejected', 'refused'], true) ? $flow->getReason() : null;

        return $this->trans('@marketplace.invoice.lifecycle.'.$flow->getStatus()).(null !== $reason ? ' : '.$reason : '').' ('.$flow->getGateway().')';
    }

    /**
     * What the seller is to do about it, in words, or null when nothing:
     * a rejected or refused invoice is corrected by a credit note and a new
     * invoice, never edited; a status waiting to be declared says why.
     */
    public function guidance(Invoice $invoice): ?string
    {
        $flow = $this->flowOf($invoice);
        if (null === $flow || $invoice->isCancelled()) {
            return null;
        }
        if (\in_array($flow->getStatus(), ['rejected', 'refused'], true)) {
            return $this->trans('@marketplace.invoice.lifecycle.'.$flow->getStatus().'_help', ['{reason}' => $flow->getReason() ?? '-']);
        }
        if ($flow->isOutcomeUnknown()) {
            return $this->awaitsConfirmation($invoice)
                ? $this->trans('@marketplace.invoice.lifecycle.outcome_unknown_confirm_help', ['{number}' => $invoice->getNumber(), '{gateway}' => $flow->getGateway(), '{reason}' => $flow->getReason() ?? '-'])
                : $this->trans('@marketplace.invoice.lifecycle.outcome_unknown_help', ['{number}' => $invoice->getNumber()]);
        }
        foreach ($flow->pending() as $pending) {
            return $this->trans('@marketplace.invoice.lifecycle.pending_help', ['{status}' => $this->trans('@marketplace.invoice.lifecycle.'.$pending['status']), '{error}' => $pending['error'] ?? '-']);
        }

        return null;
    }

    /**
     * What an approved platform routes the invoice by, written into its
     * Factur-X (FacturX::document()): the buyer's electronic address, which
     * the platform's directory gives for its SIREN - its SIRET's line first
     * -, the seller's own (marketplace.invoice.seller.electronic_address,
     * else its SIREN), the business process (BT-23), the rule. Empty for
     * an invoice that does not go to a platform.
     *
     * @return array{seller_address?: array{scheme: string, id: string}, buyer_address?: array{scheme: string, id: string}, business_process?: string, rule?: string, routing_code?: string|null}
     *
     * @throws TransmissionException the buyer has no SIREN, a SIREN the register does not know, or none the directory has
     */
    public function addressing(Invoice $invoice): array
    {
        $name = $this->channel($invoice);
        if (null === $name || !$this->isPlatform($name)) {
            return [];
        }
        $this->once($invoice);
        $buyer = $invoice->getBuyer();
        $siret = CompanyRegistry::normalize($buyer['siret'] ?? null) ?: null;
        $siren = CompanyRegistry::normalize($buyer['siren'] ?? null) ?: (null !== $siret ? substr($siret, 0, 9) : null);
        if (null === $siren) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.no_siren', ['{number}' => $invoice->getNumber()]));
        }
        if (!CompanyRegistry::isWellFormed($siren) || (null !== $siret && !CompanyRegistry::isWellFormed($siret))) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.invalid_siren', ['{siren}' => $siret ?? $siren]));
        }
        // The company register (omnistate's annuaire-entreprises), when it answers: a SIREN it does not know is refused.
        if (CompanyRegistry::NOT_FOUND === ($this->companies?->lookup($siret ?? $siren)['status'] ?? null)) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.unknown_siren', ['{siren}' => $siret ?? $siren]));
        }

        try {
            $gateway = $this->registry->get($name);
            if (!$gateway->supports(Lookup::class)) {
                throw new TransmissionException($this->trans('@marketplace.invoice.platform.no_directory', ['{gateway}' => $name]));
            }
            $lines = $gateway->lookup($siret ?? $siren);
            if ([] === $lines && null !== $siret) {
                $lines = $gateway->lookup($siren);
            }
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        $line = self::line($lines, $siret);
        if (null === $line) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.not_in_directory', ['{siren}' => $siren]));
        }
        $seller = $invoice->getSeller();
        $sellerAddress = ($seller['electronic_address'] ?? null) ?: (CompanyRegistry::normalize($seller['siren'] ?? null) ?: null);

        return array_filter([
            'seller_address' => null !== $sellerAddress ? ['scheme' => (string) (($seller['electronic_address_scheme'] ?? null) ?: '0225'), 'id' => (string) $sellerAddress] : null,
            'buyer_address' => ['scheme' => '0225', 'id' => (string) ($line->electronicAddress ?? $line->siren)],
            'business_process' => $this->businessProcess($invoice),
            'rule' => InvoiceRoute::DOMESTIC_B2B->rule(),
            'routing_code' => $line->routingCode,
        ], static fn ($v) => null !== $v);
    }

    /**
     * The invoice, its PDF (Factur-X) given, submitted to its gateway (channel()):
     * what it answers is kept beside the invoice, and an invoice handed over is
     * sent. An invoice goes to an approved platform once: a second submission is
     * refused - a rejected or refused one is corrected by a credit note and a new
     * invoice. An invoice already paid has "Encaissée" declared at once.
     *
     * @param string               $pdf     the invoice's PDF: Service\Invoices::pdf(), with $routing
     * @param string|null          $link    the invoice's signed address, for the e-mail's text
     * @param array<string, mixed> $routing addressing()
     */
    public function submit(Invoice $invoice, string $pdf, string $filename, ?string $link = null, ?string $to = null, array $routing = []): InvoiceFlow
    {
        $name = $this->channel($invoice) ?? (string) $this->gateway;
        $platform = $this->isPlatform($name);
        $row = $this->flowOf($invoice);
        if (null !== $row && $row->isOutcomeUnknown()) {
            // Submitted already, its answer lost: found again on the platform, or proved absent, before anything goes.
            if ($this->findAgain($row)) {
                return $row;
            }
        } elseif ($platform) {
            $this->once($invoice);
        }
        if ($platform && [] === $routing) {
            $routing = $this->addressing($invoice);
        }
        $bill = $this->bill($invoice, $pdf, $filename, $to, $routing);
        try {
            $flow = $this->gatewayNamed($name, $invoice, $link)->submit($bill, $invoice->getNumber());
        } catch (OutcomeUnknownException $e) {
            // The invoice left and its answer did not come back: the platform may have it. Kept as such, then
            // asked at once by its number; not found yet, it is asked again before any new submission.
            if (null === $row) {
                $row = new InvoiceFlow($invoice, $name, '');
                $this->entityManager->persist($row);
            }
            $row->outcomeUnknown($name)->routed($platform ? ($routing['rule'] ?? null) : null, $platform ? ($routing['buyer_address']['id'] ?? null) : null, $bill->sha256());
            $this->entityManager->flush();
            try {
                $flow = $this->lookUp($row);
            } catch (TransmissionException) {
                $flow = null;
            }
            if (null === $flow) {
                throw new TransmissionException($this->trans('@marketplace.invoice.platform.outcome_unknown', ['{number}' => $invoice->getNumber(), '{error}' => $e->getMessage()]), 0, $e);
            }
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
        if (null === $row) {
            $row = new InvoiceFlow($invoice, $name, $flow->reference);
            $this->entityManager->persist($row);
        } elseif ($row->isOutcomeUnknown()) {
            $row->found($flow->reference);
        } else {
            $row->restart($name, $flow->reference);
        }
        $row->routed($platform ? ($routing['rule'] ?? null) : null, $platform ? ($routing['buyer_address']['id'] ?? null) : null, $bill->sha256());
        $this->reflect($row, $flow);
        $this->entityManager->flush();
        $this->paidWithTheOrder($row);

        return $row;
    }

    /** An invoice "acquittée" (paid with the order), on an approved platform: its payment declared too. */
    private function paidWithTheOrder(InvoiceFlow $row): void
    {
        $invoice = $row->getInvoice();
        if ($this->isPlatform($row->getGateway()) && $invoice->isPaid() && !$invoice->isCreditNote()) {
            $this->declare($invoice, LifecycleStatus::PAID, $invoice->getPaidAt());
        }
    }

    /**
     * A submission whose outcome is unknown, asked of the platform by the
     * invoice's number: true when it has it - kept, as if its answer had
     * come back -, false when it has none since SEARCH_DELAY - the invoice
     * may be submitted again.
     *
     * @throws TransmissionException it cannot be told yet: the platform cannot be asked, or "none" came too soon
     */
    private function findAgain(InvoiceFlow $row): bool
    {
        $invoice = $row->getInvoice();
        $flow = $this->lookUp($row);
        if (null !== $flow) {
            $this->adopt($row, $flow);
            $this->paidWithTheOrder($row);

            return true;
        }
        if ($row->getUpdatedAt() > (new \DateTimeImmutable())->sub(new \DateInterval(self::SEARCH_DELAY))) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.outcome_unknown_wait', ['{number}' => $invoice->getNumber()]));
        }

        return false;
    }

    /**
     * The flow the row's gateway has under the invoice's number (the
     * trackingId it was submitted with), null when it has none.
     *
     * @throws TransmissionException the gateway cannot look it up, or did not answer
     */
    private function lookUp(InvoiceFlow $row): ?Flow
    {
        $invoice = $row->getInvoice();
        try {
            $gateway = $this->gatewayNamed($row->getGateway(), $invoice);
            if (!$gateway instanceof FlowLookupInterface || !$gateway->supports(FindFlow::class)) {
                throw new TransmissionException($this->trans('@marketplace.invoice.platform.outcome_unknown_check', ['{number}' => $invoice->getNumber(), '{gateway}' => $row->getGateway()]));
            }
            $flow = $gateway->findFlow($invoice->getNumber());
            if ($row->isLookupInconclusive()) {
                // An answer at last: no longer a person's to settle.
                $row->lookedUp();
                $this->entityManager->flush();
            }

            return $flow;
        } catch (UnreachableException|InvalidKeyException|UsageLimitReachedException $e) {
            // The platform could not be asked: asked again later.
            throw new TransmissionException($e->getMessage(), 0, $e);
        } catch (ProviderException|InvalidConfigException|NotSupportedException $e) {
            // Asked, and no answer to rely on: a person settles it (confirmDeposit()).
            $row->lookupInconclusive($e->getMessage());
            $this->entityManager->flush();
            throw new TransmissionException($e->getMessage(), 0, $e);
        } catch (OmnibillException $e) {
            throw new TransmissionException($e->getMessage(), 0, $e);
        }
    }

    /** Whether the row's gateway can look a submission up by the invoice's number (FindFlow). */
    private function canLookUp(InvoiceFlow $row): bool
    {
        try {
            $gateway = $this->gatewayNamed($row->getGateway(), $row->getInvoice());
        } catch (OmnibillException) {
            return false;
        }

        return $gateway instanceof FlowLookupInterface && $gateway->supports(FindFlow::class);
    }

    /**
     * Whether the invoice's submission waits for a person: its outcome is
     * unknown, and the platform cannot settle it - its gateway cannot look
     * it up, or its last lookup answered nothing to rely on. They check on
     * the platform, then confirmDeposit().
     */
    public function awaitsConfirmation(Invoice $invoice): bool
    {
        $row = $this->flowOf($invoice);

        return null !== $row && $row->isOutcomeUnknown() && ($row->isLookupInconclusive() || !$this->canLookUp($row));
    }

    /**
     * A submission whose outcome is unknown, settled by a person who checked
     * on the platform (only when awaitsConfirmation()):
     *
     *   - $deposited, the platform has it under $reference, the flow id it
     *     shows - what the invoice is followed by from then on (its status,
     *     the webhook, the statuses declared): kept as if its answer had come
     *     back, its status asked of the gateway where it can say, deposited
     *     (sent, on a gateway that is no approved platform) otherwise;
     *   - not, the transmission is forgotten (the row removed: nothing stands
     *     on the platform): the next submission deposits it again, once.
     *
     * Returns the row kept, null once forgotten.
     *
     * @throws TransmissionException nothing to settle, or no flow id given
     */
    public function confirmDeposit(Invoice $invoice, bool $deposited, ?string $reference = null): ?InvoiceFlow
    {
        $row = $this->flowOf($invoice);
        if (null === $row || !$this->awaitsConfirmation($invoice)) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.nothing_to_confirm', ['{number}' => $invoice->getNumber()]));
        }
        if (!$deposited) {
            $this->logger?->notice('The invoice {number} is not on the platform, a person says: deposited again.', ['number' => $invoice->getNumber()]);
            $this->entityManager->remove($row);
            $this->entityManager->flush();

            return null;
        }
        $reference = trim((string) $reference);
        if ('' === $reference) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.flow_id_needed', ['{number}' => $invoice->getNumber()]));
        }
        $row->found(mb_substr($reference, 0, 255));
        try {
            $gateway = $this->gatewayNamed($row->getGateway(), $invoice);
            if ($gateway->supports(FetchStatus::class)) {
                $this->reflect($row, $gateway->fetchStatus($this->flow($row)));
            }
        } catch (OmnibillException $e) {
            // Its status is asked again by refresh(); what the person saw stands meanwhile.
            $this->logger?->warning('The status of the invoice {number} confirmed on the platform could not be read: {error}', ['number' => $invoice->getNumber(), 'error' => $e->getMessage()]);
        }
        if (null === $row->getStatus()) {
            $status = $this->isPlatform($row->getGateway()) ? LifecycleStatus::DEPOSITED : LifecycleStatus::SENT;
            $row->setHistory([self::row(new StatusChange($status, $invoice->getNumber(), new \DateTimeImmutable()))]);
            $invoice->markSent();
        }
        $this->logger?->notice('The invoice {number} is on the platform under {reference}, a person says.', ['number' => $invoice->getNumber(), 'reference' => $reference]);
        $this->entityManager->flush();
        $this->paidWithTheOrder($row);

        return $row;
    }

    /** A submission whose outcome was unknown, found on the platform: kept under its reference. */
    private function adopt(InvoiceFlow $row, Flow $flow): void
    {
        $row->found($flow->reference);
        $this->reflect($row, $flow);
        $this->entityManager->flush();
    }

    /** An invoice is deposited on an approved platform once: a correction is a credit note and a new invoice. */
    private function once(Invoice $invoice): void
    {
        $row = $this->flowOf($invoice);
        if (null !== $row && !$row->isOutcomeUnknown() && $this->isPlatform($row->getGateway())) {
            throw new TransmissionException($this->trans('@marketplace.invoice.platform.once', ['{number}' => $invoice->getNumber(), '{status}' => $this->describe($invoice) ?? '-']));
        }
    }

    /**
     * Asks the gateway where the invoice stands, and keeps it; null when it
     * went through none. A submission whose outcome is unknown is looked up
     * by the invoice's number: kept once the platform has it.
     */
    public function refresh(Invoice $invoice): ?InvoiceFlow
    {
        $row = $this->flowOf($invoice);
        if (null === $row || !$this->available($row->getGateway())) {
            return null;
        }
        if ($row->isOutcomeUnknown()) {
            if (null !== ($flow = $this->lookUp($row))) {
                $this->adopt($row, $flow);
                $this->paidWithTheOrder($row);
            }

            return $row;
        }
        try {
            $gateway = $this->gatewayNamed($row->getGateway(), $invoice);
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

    /**
     * Declares a status of one's own invoice to the gateway it went through,
     * once: kept on the invoice (InvoiceFlow::$declared), sent at once when the
     * gateway takes it, left pending otherwise - an approved platform without
     * the application's lifecycle message writer, a platform unreachable - and
     * sent again by sendPending(). Never throws for what the gateway answers:
     * a payment confirmed is not undone by a platform that fails.
     */
    public function declare(Invoice $invoice, LifecycleStatus $status, ?\DateTimeImmutable $at = null, ?string $reason = null): void
    {
        $row = $this->flowOf($invoice);
        if (null === $row || $row->hasDeclared($status->value) || !$this->available($row->getGateway())) {
            return;
        }
        $amount = LifecycleStatus::PAID === $status ? self::decimal($invoice->getTotal(), $invoice->getCurrency()) : null;
        $index = $row->declare($status->value, $at ?? new \DateTimeImmutable(), $amount, $reason);
        $this->send($row, $index);
        $this->entityManager->flush();
    }

    /** Sends again the statuses declared and not taken: how many went. */
    public function sendPending(): int
    {
        $sent = 0;
        $rows = $this->entityManager->getRepository(InvoiceFlow::class)->createQueryBuilder('f')
            ->where('f.declared LIKE :unsent')->setParameter('unsent', '%false%')
            ->getQuery()->getResult();
        foreach ($rows as $row) {
            foreach (array_keys($row->pending()) as $index) {
                $sent += (int) $this->send($row, $index);
            }
        }
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * A status whose outcome was unknown (InvoiceFlow::unknownDeclared()),
     * settled by a person who checked on the platform: there ($taken), it is
     * marked sent and kept in the invoice's history; not there, it is sent
     * again now, once. Whether it went - false when that status waits for
     * no one, or did not go (its error kept, said on the invoice's page).
     */
    public function confirmStatus(Invoice $invoice, int $index, bool $taken): bool
    {
        $row = $this->flowOf($invoice);
        $declared = $row?->unknownDeclared()[$index] ?? null;
        if (null === $row || null === $declared) {
            return false;
        }
        if ($taken) {
            $row->declaredSent($index, null);
            $status = LifecycleStatus::tryFrom($declared['status']);
            $entry = null === $status ? null : self::row(new StatusChange($status, $invoice->getNumber(), new \DateTimeImmutable($declared['at']), $declared['reason'] ?? null));
            if (null !== $entry && !\in_array($entry, $row->getHistory(), true)) {
                $row->setHistory([...$row->getHistory(), $entry]);
                $this->settle($row);
            }
            $this->entityManager->flush();

            return true;
        }
        $row->declaredRetried($index);
        $sent = $this->send($row, $index);
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * Reads the lifecycle flows a platform has for the shop since its cursor
     * (Receive, its kinds the lifecycle flows only: the suppliers' invoices,
     * omnibase/ledger's, are neither downloaded nor answered): a status a
     * buyer declared about one of the shop's invoices - approved, refused
     * and why, paid - is added to that invoice's history; a status about
     * another invoice is left. The cursor is the shop's own, given with
     * those kinds only. How many statuses were taken.
     *
     * @param string|null $name the gateway; the configured one by default
     */
    public function receiveStatuses(?string $name = null): int
    {
        $name ??= $this->gateway;
        if (null === $name || !$this->isPlatform($name)) {
            return 0;
        }
        $gateway = $this->registry->get($name);
        if (!$gateway->supports(Receive::class)) {
            return 0;
        }
        $inbox = $this->entityManager->getRepository(InvoiceInbox::class)->findOneBy(['gateway' => $name]);
        if (null === $inbox) {
            $inbox = new InvoiceInbox($name);
            $this->entityManager->persist($inbox);
        }
        $taken = 0;
        try {
            for ($pages = 0; $pages < 20; ++$pages) {
                $page = $gateway->execute(new Receive($inbox->getCursor(), 50, [FlowKind::LIFECYCLE]))->getInbox() ?? throw new TransmissionException(sprintf('The "%s" gateway answered no inbox.', $name));
                foreach ($page->items as $item) {
                    $change = $item->status;
                    if (null === $change || Direction::IN !== $item->flow->direction || null === $change->invoiceNumber) {
                        continue;
                    }
                    $invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['number' => $change->invoiceNumber]);
                    $row = null !== $invoice ? $this->flowOf($invoice) : null;
                    if (null === $row || $row->getGateway() !== $name) {
                        continue;
                    }
                    $entry = self::row($change);
                    if (!\in_array($entry, $row->getHistory(), true)) {
                        $row->setHistory([...$row->getHistory(), $entry]);
                        $this->settle($row);
                        ++$taken;
                    }
                }
                $inbox->advance($page->cursor);
                $this->entityManager->flush();
                if (!$page->more) {
                    break;
                }
            }
        } catch (OmnibillException $e) {
            $this->entityManager->flush();
            throw new TransmissionException($e->getMessage(), 0, $e);
        }

        return $taken;
    }

    public function supportsNotify(string $gateway): bool
    {
        return ($this->registry?->has($gateway) ?? false) && $this->registry->get($gateway)->supports(Notify::class);
    }

    /**
     * A gateway's callback (an approved platform's webhook): its signature
     * checked by the gateway, the invoice it is about updated. A flow coming
     * in (a buyer's lifecycle message) has its statuses received.
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
        if (Direction::IN === $flow->direction) {
            $this->receiveStatuses($gateway);

            return null;
        }
        $row = $this->entityManager->getRepository(InvoiceFlow::class)->findOneBy(['reference' => $flow->reference]);
        if (null === $row && null !== $flow->invoiceNumber && null !== ($invoice = $this->entityManager->getRepository(Invoice::class)->findOneBy(['number' => $flow->invoiceNumber]))) {
            $row = $this->flowOf($invoice);
        }
        if (null === $row) {
            return null;
        }
        if ($row->isOutcomeUnknown()) {
            // The platform tells of the submission whose answer was lost: kept under its reference.
            $row->found($flow->reference);
        }
        $this->reflect($row, $flow);
        $this->entityManager->flush();

        return $row->getInvoice();
    }

    /** Sends one declared status; whether it went. */
    private function send(InvoiceFlow $row, int $index): bool
    {
        $declared = $row->getDeclared()[$index] ?? null;
        if (null === $declared || $declared['sent'] || ($declared['unknown'] ?? false)) {
            // Sent, or its outcome unknown: the platform may have it, a person checks before it goes again.
            return false;
        }
        $invoice = $row->getInvoice();
        if ($row->isOutcomeUnknown()) {
            $row->declaredFailed($index, $this->trans('@marketplace.invoice.lifecycle.outcome_unknown'));

            return false;
        }
        if ('rejected' === $row->getStatus() || $invoice->isCancelled()) {
            // Rejected by the platform, or cancelled: no status follows on that invoice.
            $row->declaredFailed($index, sprintf('The invoice %s is %s: no status follows.', $invoice->getNumber(), $invoice->isCancelled() ? 'cancelled' : 'rejected'));

            return false;
        }
        try {
            $gateway = $this->gatewayNamed($row->getGateway(), $invoice);
            if (!$gateway->supports(SetStatus::class)) {
                $row->declaredFailed($index, sprintf('The "%s" gateway declares no status.', $row->getGateway()));

                return false;
            }
            $status = LifecycleStatus::from($declared['status']);
            $at = new \DateTimeImmutable($declared['at']);
            $message = null;
            if ($this->isPlatform($row->getGateway())) {
                $message = $this->messages?->write($invoice, $status->value, $at, $declared['reason'] ?? null, $declared['amount'] ?? null, $this->byRate($invoice), $row->getRecipientAddress());
                if (null === $message) {
                    $row->declaredFailed($index, $this->trans('@marketplace.invoice.platform.no_lifecycle_writer'));

                    return false;
                }
            }
            $change = [
                'status' => $status, 'invoiceNumber' => $invoice->getNumber(), 'at' => $at, 'reason' => $declared['reason'] ?? null, 'amount' => $declared['amount'] ?? null,
                'file' => $message['content'] ?? null, 'filename' => $message['filename'] ?? null,
            ];
            // The amount received at each rate (omnibill 1.2), for "Encaissée".
            if (LifecycleStatus::PAID === $status && property_exists(StatusChange::class, 'amounts')) {
                $change['amounts'] = self::basisPoints($this->byRate($invoice));
            }
            $answer = $gateway->setStatus(new StatusChange(...$change), $this->flow($row));
            $this->reflect($row, $answer);
            $row->declaredSent($index, $answer->reference);

            return true;
        } catch (OutcomeUnknownException $e) {
            $row->declaredUnknown($index, $this->trans('@marketplace.invoice.platform.status_outcome_unknown', ['{error}' => $e->getMessage()]));
            $this->logger?->warning('The status {status} of the invoice {number} may have been taken: {error}', ['status' => $declared['status'], 'number' => $invoice->getNumber(), 'error' => $e->getMessage()]);

            return false;
        } catch (OmnibillException|\ValueError $e) {
            $row->declaredFailed($index, $e->getMessage());
            $this->logger?->warning('The status {status} of the invoice {number} was not sent: {error}', ['status' => $declared['status'], 'number' => $invoice->getNumber(), 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** What the gateway said, beside the invoice; the invoice sent once handed over, paid once paid. */
    private function reflect(InvoiceFlow $row, Flow $flow): void
    {
        $invoice = $row->getInvoice();
        $history = array_map(self::row(...), $flow->history);
        if ([] === $history && null !== $flow->status) {
            $history = [self::row(new StatusChange($flow->status, $invoice->getNumber(), $flow->updatedAt))];
        }
        // The gateway answers the whole history: the one it was given (flow(), the buyer's statuses received
        // included) and what it adds.
        if ([] !== $history) {
            $row->setHistory($history);
        }
        $status = null !== $row->getStatus() ? LifecycleStatus::tryFrom($row->getStatus()) : null;
        if (null !== $status && !\in_array($status, [LifecycleStatus::REJECTED, LifecycleStatus::REFUSED], true)) {
            $invoice->markSent($flow->submittedAt ?? new \DateTimeImmutable());
        }
        $this->settle($row);
    }

    /** A buyer's or platform's "paid" makes the invoice paid. */
    private function settle(InvoiceFlow $row): void
    {
        $invoice = $row->getInvoice();
        if ('paid' === $row->getStatus() && !$invoice->isPaid() && !$invoice->isCancelled() && !$invoice->isCreditNote()) {
            $last = $row->getHistory()[array_key_last($row->getHistory())];
            $invoice->markPaid(null !== $last['at'] ? new \DateTimeImmutable($last['at']) : new \DateTimeImmutable());
        }
    }

    /** @return array{status: string, code: int|null, at: string|null, reason: string|null} */
    public static function row(StatusChange $change): array
    {
        return ['status' => $change->status->value, 'code' => $change->status->code(), 'at' => $change->at?->format(\DATE_ATOM), 'reason' => $change->reason];
    }

    /**
     * The invoicing frame of the French reform (BT-23), from the nature of the
     * operations (marketplace.invoice.operation): B1 goods, S1 services, M1
     * both; B2, S2, M2 for an invoice already paid. As the public summaries of
     * the DGFiP's external specifications give it - to be confirmed with the
     * platform.
     */
    private function businessProcess(Invoice $invoice): string
    {
        $letter = match ($this->operation) {
            'services' => 'S',
            'mixed' => 'M',
            default => 'B',
        };

        return $letter.($invoice->isPaid() && !$invoice->isCreditNote() ? '2' : '1');
    }

    /**
     * The invoice's total by VAT rate, as the payment data asks it.
     *
     * @return list<array{rate: float, amount: string}>
     */
    private function byRate(Invoice $invoice): array
    {
        $rates = [];
        foreach ($invoice->getTotals()['vat_breakdown'] ?? [] as $vat) {
            $rates[] = ['rate' => (float) $vat['rate'], 'amount' => self::decimal((int) $vat['base'] + (int) $vat['vat'], $invoice->getCurrency())];
        }

        return $rates;
    }

    /**
     * The amounts by rate as omnibill's StatusChange::$amounts takes them: the
     * rate in basis points (20 % is 2000, 5.5 % is 550) => the amount.
     *
     * @param list<array{rate: float, amount: string}> $rates byRate()
     *
     * @return array<int, string>
     */
    private static function basisPoints(array $rates): array
    {
        $amounts = [];
        foreach ($rates as $rate) {
            $amounts[(int) round($rate['rate'] * 100)] = $rate['amount'];
        }

        return $amounts;
    }

    /**
     * The directory's line to send to: the establishment's when a SIRET is
     * known, else the legal unit's (no SIRET, no routing code), else the
     * first that receives.
     *
     * @param list<Addressing> $lines
     */
    private static function line(array $lines, ?string $siret): ?Addressing
    {
        $enabled = array_values(array_filter($lines, static fn (Addressing $a) => $a->isEnabled()));
        foreach ($enabled as $line) {
            if (null !== $siret && $line->siret === $siret && null === $line->routingCode) {
                return $line;
            }
        }
        foreach ($enabled as $line) {
            if (null === $line->siret && null === $line->routingCode) {
                return $line;
            }
        }

        return $enabled[0] ?? null;
    }

    private function flow(InvoiceFlow $row): Flow
    {
        $number = $row->getInvoice()->getNumber();

        return new Flow($row->getReference(), trackingId: $number, invoiceNumber: $number, status: null !== $row->getStatus() ? LifecycleStatus::tryFrom($row->getStatus()) : null, history: array_values(array_filter(array_map(static fn (array $r) => null === ($s = LifecycleStatus::tryFrom($r['status'])) ? null : new StatusChange($s, $number, null !== $r['at'] ? new \DateTimeImmutable($r['at']) : null, $r['reason'] ?? null), $row->getHistory()))));
    }

    /** Whether that gateway can be used: configured in omnibill, or "email" with omnibill/email and a mailer. */
    private function available(?string $name): bool
    {
        if (null === $name || '' === $name) {
            return false;
        }

        return ($this->registry?->has($name) ?? false) || ('email' === $name && $this->canMail());
    }

    private function gatewayNamed(string $name, Invoice $invoice, ?string $link = null): GatewayInterface
    {
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
        $subject = $this->trans($invoice->isCreditNote() ? '@marketplace.invoice.mail.credit_subject' : '@marketplace.invoice.mail.subject', ['{number}' => $invoice->getNumber()]);
        $body = $this->trans('@marketplace.invoice.mail.hello', ['{name}' => $invoice->getBuyer()['name'] ?? ''])."\n\n"
            .$this->trans($invoice->isCreditNote() ? '@marketplace.invoice.mail.credit_text' : '@marketplace.invoice.mail.text', ['{number}' => $invoice->getNumber(), '{total}' => (new InvoiceTwigExtension())->money($invoice->getTotal(), $invoice->getCurrency()), '{seller}' => $invoice->getSeller()['name'] ?? ''])
            .(null !== $link ? "\n\n".$this->trans('@marketplace.invoice.mail.action').' : '.$link : '')
            ."\n\n".($invoice->getSeller()['name'] ?? '');

        return ['subject' => $subject, 'body' => $body];
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator?->trans($key, $parameters) ?? strtr($key, $parameters);
    }

    /** @param array<string, mixed> $routing addressing() */
    private function bill(Invoice $invoice, string $pdf, string $filename, ?string $to, array $routing = []): BillInvoice
    {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();

        return new BillInvoice(
            $pdf,
            $filename,
            Syntax::FACTURX,
            $invoice->getNumber(),
            new Party($seller['name'] ?? null, $seller['siren'] ?? null, $seller['siret'] ?? null, $routing['seller_address']['id'] ?? null, email: $seller['email'] ?? null, country: $seller['country'] ?? 'FR'),
            new Party($buyer['name'] ?? null, $buyer['siren'] ?? null, $buyer['siret'] ?? null, $routing['buyer_address']['id'] ?? null, $routing['routing_code'] ?? null, email: $to ?? $buyer['email'] ?? null, country: $buyer['country'] ?? 'FR'),
            $invoice->getIssuedAt(),
            self::decimal($invoice->getTotal(), $invoice->getCurrency()),
            $invoice->getCurrency(),
            rule: $routing['rule'] ?? null,
        );
    }

    /** An amount in the currency's smallest unit, as a decimal string: "1234.50". */
    public static function decimal(int $amount, string $currency): string
    {
        $digits = Currencies::getFractionDigits($currency);

        return number_format($amount / 10 ** $digits, $digits, '.', '');
    }
}
