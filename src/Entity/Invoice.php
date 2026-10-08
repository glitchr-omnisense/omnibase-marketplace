<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Repository\InvoiceRepository;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invoice of the shop, or a credit note (avoir): what was sold, to whom,
 * by whom, for how much - written once, when it is issued
 * (Service\Invoices::issue(), ::credit()), and never again.
 *
 * Its number is the next of a sequence without a gap, one per series and
 * year (F-2026-000041; A-2026-000003 for a credit note). The seller and the
 * buyer are copied onto it as they are that day (name, address, SIREN,
 * SIRET, VAT number), and so are its lines and amounts, taken from the order
 * (its VAT included: the order computed it). What follows - sent, paid,
 * cancelled by a credit note - is its state; nothing else of it changes:
 * Doctrine refuses the update. A correction is a credit note, then a new
 * invoice.
 *
 * Amounts are integers in the currency's smallest unit, as the order's.
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'marketplace_invoice_sequence', columns: ['series', 'year', 'sequence'])]
#[ORM\UniqueConstraint(name: 'marketplace_invoice_number', columns: ['number'])]
class Invoice
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const STATE_ISSUED = 'issued';
    public const STATE_SENT = 'sent';
    public const STATE_PAID = 'paid';
    public const STATE_CANCELLED = 'cancelled';

    /** What may still change once issued: where it stands. */
    public const MUTABLE = ['state', 'sentAt', 'paidAt', 'cancelledAt', 'creditNote'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 16)]
    protected string $type;

    #[ORM\Column(type: 'string', length: 16)]
    protected string $series;

    #[ORM\Column(type: 'integer')]
    protected int $year;

    #[ORM\Column(type: 'integer')]
    protected int $sequence;

    #[ORM\Column(type: 'string', length: 40)]
    protected string $number;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $issuedAt;

    /** The date of the sale: the order's payment, its delivery when it is known. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $soldAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $dueAt;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Order $order;

    /** The order's reference, kept should the order go. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $orderReference;

    /** For a credit note: the invoice it cancels. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?Invoice $credited;

    /** For an invoice: the credit note that cancelled it. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Invoice $creditNote = null;

    #[ORM\Column(type: 'string', length: 3)]
    protected string $currency;

    /** @var array<string, mixed> the seller that day */
    #[ORM\Column(type: 'json')]
    protected array $seller;

    /** @var array<string, mixed> the buyer that day */
    #[ORM\Column(type: 'json')]
    protected array $buyer;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    protected array $lines;

    /** @var array<string, mixed> totals, charges, VAT by rate */
    #[ORM\Column(type: 'json')]
    protected array $totals;

    /** @var list<string> the mentions it carries, as printed */
    #[ORM\Column(type: 'json')]
    protected array $mentions;

    #[ORM\Column(type: 'string', length: 16)]
    protected string $state = self::STATE_ISSUED;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $cancelledAt = null;

    /**
     * @param array<string, mixed>        $seller
     * @param array<string, mixed>        $buyer
     * @param list<array<string, mixed>>  $lines
     * @param array<string, mixed>        $totals
     * @param list<string>                $mentions
     */
    public function __construct(
        string $type,
        string $series,
        int $year,
        int $sequence,
        string $number,
        \DateTimeImmutable $issuedAt,
        ?Order $order,
        string $currency,
        array $seller,
        array $buyer,
        array $lines,
        array $totals,
        array $mentions = [],
        ?\DateTimeImmutable $soldAt = null,
        ?\DateTimeImmutable $dueAt = null,
        ?Invoice $credited = null,
    ) {
        if (!\in_array($type, [self::TYPE_INVOICE, self::TYPE_CREDIT_NOTE], true)) {
            throw new \InvalidArgumentException(sprintf('An invoice is an "%s" or a "%s", not "%s".', self::TYPE_INVOICE, self::TYPE_CREDIT_NOTE, $type));
        }
        $this->type = $type;
        $this->series = $series;
        $this->year = $year;
        $this->sequence = $sequence;
        $this->number = $number;
        $this->issuedAt = $issuedAt;
        $this->order = $order;
        $this->orderReference = $order?->getReference();
        $this->currency = $currency;
        $this->seller = $seller;
        $this->buyer = $buyer;
        $this->lines = $lines;
        $this->totals = $totals;
        $this->mentions = array_values($mentions);
        $this->soldAt = $soldAt;
        $this->dueAt = $dueAt;
        $this->credited = $credited;
    }

    public function __toString(): string
    {
        return $this->number;
    }

    /** Issued, nothing but its state changes: an update of anything else is refused. */
    #[ORM\PreUpdate]
    public function refuseChanges(PreUpdateEventArgs $event): void
    {
        $changed = array_diff(array_keys($event->getEntityChangeSet()), self::MUTABLE);
        if ([] !== $changed) {
            throw new \LogicException(sprintf('The invoice %s is issued: it does not change (%s). Correct it with a credit note.', $this->number, implode(', ', $changed)));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isCreditNote(): bool
    {
        return self::TYPE_CREDIT_NOTE === $this->type;
    }

    public function getSeries(): string
    {
        return $this->series;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getSoldAt(): ?\DateTimeImmutable
    {
        return $this->soldAt;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function getOrderReference(): ?string
    {
        return $this->orderReference;
    }

    public function getCredited(): ?Invoice
    {
        return $this->credited;
    }

    public function getCreditNote(): ?Invoice
    {
        return $this->creditNote;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** @return array<string, mixed> */
    public function getSeller(): array
    {
        return $this->seller;
    }

    /** @return array<string, mixed> */
    public function getBuyer(): array
    {
        return $this->buyer;
    }

    /** @return list<array<string, mixed>> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return array<string, mixed> */
    public function getTotals(): array
    {
        return $this->totals;
    }

    public function getTotal(): int
    {
        return (int) ($this->totals['total'] ?? 0);
    }

    /** @return list<string> */
    public function getMentions(): array
    {
        return $this->mentions;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isCancelled(): bool
    {
        return self::STATE_CANCELLED === $this->state;
    }

    public function isPaid(): bool
    {
        return null !== $this->paidAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function markSent(\DateTimeImmutable $at = new \DateTimeImmutable()): static
    {
        $this->sentAt ??= $at;
        if (self::STATE_ISSUED === $this->state) {
            $this->state = self::STATE_SENT;
        }

        return $this;
    }

    public function markPaid(\DateTimeImmutable $at = new \DateTimeImmutable()): static
    {
        if ($this->isCancelled()) {
            throw new \LogicException(sprintf('The invoice %s is cancelled.', $this->number));
        }
        $this->paidAt ??= $at;
        $this->state = self::STATE_PAID;

        return $this;
    }

    /** Cancelled by that credit note: Service\Invoices::credit(). */
    public function cancelBy(Invoice $creditNote, \DateTimeImmutable $at = new \DateTimeImmutable()): static
    {
        if ($this->isCreditNote() || !$creditNote->isCreditNote() || $creditNote->getCredited() !== $this) {
            throw new \LogicException('An invoice is cancelled by a credit note made for it.');
        }
        if ($this->isCancelled()) {
            throw new \LogicException(sprintf('The invoice %s is already cancelled by %s.', $this->number, $this->creditNote?->getNumber()));
        }
        $this->creditNote = $creditNote;
        $this->cancelledAt = $at;
        $this->state = self::STATE_CANCELLED;

        return $this;
    }
}
