<?php

namespace Base\Marketplace\Invoice\Transmission\Entity;

use Base\Marketplace\Entity\Invoice;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where an invoice stands on the omnisense/omnibill gateway it went through:
 * the gateway, its reference of the transmission, the lifecycle statuses
 * known (sent, deposited, refused, paid...). One per invoice, beside it -
 * the invoice itself does not change once issued.
 *
 * Mapped only when omnisense/omnibill is installed (MarketplaceExtension):
 * a site without the family has no table for it, and needs none.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_invoice_flow')]
#[ORM\UniqueConstraint(name: 'marketplace_invoice_flow_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'marketplace_invoice_flow_reference', columns: ['reference'])]
class InvoiceFlow
{
    /**
     * The status of a submission whose outcome is unknown: the request left and its answer
     * failed (omnibill's OutcomeUnknownException), the platform may have taken the invoice.
     * No reference yet; looked up by the invoice's number before anything is sent again.
     */
    public const OUTCOME_UNKNOWN = 'outcome_unknown';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    protected Invoice $invoice;

    /** The gateway's name in omnibill.gateways ("email", "afnor"...). */
    #[ORM\Column(type: 'string', length: 32)]
    protected string $gateway;

    /** The gateway's reference of the transmission: a flow id, a deposit number, a message id. */
    #[ORM\Column(type: 'string', length: 255)]
    protected string $reference;

    /** The latest lifecycle status (Omnibill\Model\LifecycleStatus's value). */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected ?string $status = null;

    /** @var list<array{status: string, code: int|null, at: string|null, reason: string|null}> oldest first */
    #[ORM\Column(type: 'json')]
    protected array $history = [];

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $updatedAt;

    /** The platform's processing rule it went under (B2B), when it went to an approved platform. */
    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    protected ?string $rule = null;

    /** The buyer's electronic address the directory gave (Omnibill\Model\Addressing), the file was routed to. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    protected ?string $recipientAddress = null;

    /** The SHA-256 of the file transmitted: the one the platform keeps. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $fileSha256 = null;

    /**
     * The statuses the shop declares about its own invoice (paid...), oldest first: sent to the
     * gateway, or waiting to be - an approved platform takes a status as a lifecycle message
     * (CDAR) only, which the application writes (Invoice\Transmission\LifecycleMessageInterface).
     *
     * @var list<array{status: string, at: string, sent: bool, amount?: ?string, reason?: ?string, flow?: ?string, error?: ?string, unknown?: bool}> unknown: its outcome is unknown (declaredUnknown())
     */
    #[ORM\Column(type: 'json')]
    protected array $declared = [];

    public function __construct(Invoice $invoice, string $gateway, string $reference)
    {
        $this->invoice = $invoice;
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    /** Sent again, through a gateway: a new transmission, its history afresh. */
    public function restart(string $gateway, string $reference): static
    {
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->status = null;
        $this->history = [];
        $this->declared = [];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Submitted, and the answer lost: the platform may have it, under no reference known yet. */
    public function outcomeUnknown(string $gateway): static
    {
        $this->restart($gateway, '');
        $this->status = self::OUTCOME_UNKNOWN;

        return $this;
    }

    /** Its submission found again on the platform, under this reference: what was declared meanwhile kept. */
    public function found(string $reference): static
    {
        $this->reference = $reference;
        $this->status = null;
        $this->history = [];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Whether its submission's outcome is unknown: to be looked up, never sent again blindly. */
    public function isOutcomeUnknown(): bool
    {
        return self::OUTCOME_UNKNOWN === $this->status;
    }

    /** How it went to an approved platform: the rule, the buyer's address, the file's fingerprint. */
    public function routed(?string $rule, ?string $recipientAddress, ?string $fileSha256): static
    {
        $this->rule = $rule;
        $this->recipientAddress = $recipientAddress;
        $this->fileSha256 = $fileSha256;

        return $this;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    public function getRecipientAddress(): ?string
    {
        return $this->recipientAddress;
    }

    public function getFileSha256(): ?string
    {
        return $this->fileSha256;
    }

    /** @return list<array{status: string, at: string, sent: bool, amount?: ?string, reason?: ?string, flow?: ?string, error?: ?string, unknown?: bool}> */
    public function getDeclared(): array
    {
        return $this->declared;
    }

    /** Whether that status was declared already, sent or waiting. */
    public function hasDeclared(string $status): bool
    {
        return [] !== array_filter($this->declared, static fn (array $d) => $d['status'] === $status);
    }

    /** A status declared, waiting to be sent: its index. */
    public function declare(string $status, \DateTimeImmutable $at, ?string $amount = null, ?string $reason = null): int
    {
        $this->declared[] = ['status' => $status, 'at' => $at->format(\DATE_ATOM), 'sent' => false, 'amount' => $amount, 'reason' => $reason, 'flow' => null, 'error' => null];
        $this->updatedAt = new \DateTimeImmutable();

        return \count($this->declared) - 1;
    }

    public function declaredSent(int $index, ?string $flow): static
    {
        $this->declared[$index] = ['sent' => true, 'flow' => $flow, 'error' => null] + $this->declared[$index];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function declaredFailed(int $index, string $error): static
    {
        $this->declared[$index]['error'] = mb_substr($error, 0, 255);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * The status's message left and its answer was lost: the platform may have it. It is not
     * sent again by itself (sendPending() leaves it): a person checks on the platform.
     */
    public function declaredUnknown(int $index, string $error): static
    {
        $this->declared[$index]['unknown'] = true;

        return $this->declaredFailed($index, $error);
    }

    /** @return array<int, array{status: string, at: string, sent: bool, amount?: ?string, reason?: ?string, flow?: ?string, error?: ?string, unknown?: bool}> the statuses declared and not sent yet, by index */
    public function pending(): array
    {
        return array_filter($this->declared, static fn (array $d) => !$d['sent']);
    }

    /** The latest status's reason: why the platform rejected it, why the buyer refused it. */
    public function getReason(): ?string
    {
        return [] === $this->history ? null : ($this->history[array_key_last($this->history)]['reason'] ?? null);
    }

    /** Whether nothing follows its status: paid, refused, rejected. */
    public function isFinal(): bool
    {
        return \in_array($this->status, ['paid', 'refused', 'rejected'], true);
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    /** @return list<array{status: string, code: int|null, at: string|null, reason: string|null}> */
    public function getHistory(): array
    {
        return $this->history;
    }

    /** @param list<array{status: string, code: int|null, at: string|null, reason: string|null}> $history */
    public function setHistory(array $history): static
    {
        $this->history = array_values($history);
        $this->status = [] === $this->history ? null : $this->history[array_key_last($this->history)]['status'];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
