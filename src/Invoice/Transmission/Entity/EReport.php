<?php

namespace Base\Marketplace\Invoice\Transmission\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One e-reporting period of the shop (CGI art. 290 and 290 A): the data an
 * approved platform must receive for the sales that are no e-invoice - to
 * private persons, aggregated by day; to companies abroad, invoice by
 * invoice - or for their payments, as Invoice\Transmission\EReporting
 * collected them, and where their transmission stands.
 *
 * The file itself is the application's to write (EReportWriterInterface):
 * without a writer the period stays COLLECTED, its data kept here, and is
 * sent once there is one.
 *
 * Mapped only when omnisense/omnibill is installed (MarketplaceExtension).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_ereport')]
#[ORM\UniqueConstraint(name: 'marketplace_ereport_period', fields: ['kind', 'startsOn'])]
class EReport
{
    public const TRANSACTIONS = 'transactions';
    public const PAYMENTS = 'payments';

    public const COLLECTED = 'collected';
    public const SENT = 'sent';
    public const FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /** TRANSACTIONS or PAYMENTS. */
    #[ORM\Column(type: 'string', length: 16)]
    protected string $kind;

    #[ORM\Column(type: 'date_immutable')]
    protected \DateTimeImmutable $startsOn;

    #[ORM\Column(type: 'date_immutable')]
    protected \DateTimeImmutable $endsOn;

    /** The last day to transmit it, as the regime's table says. */
    #[ORM\Column(type: 'date_immutable')]
    protected \DateTimeImmutable $dueOn;

    /** The seller's VAT regime it was collected for (marketplace.invoice.vat_regime). */
    #[ORM\Column(type: 'string', length: 24)]
    protected string $regime;

    /** @var array<string, mixed> what was collected: see EReporting::collect() */
    #[ORM\Column(type: 'json')]
    protected array $data = [];

    #[ORM\Column(type: 'string', length: 16)]
    protected string $state = self::COLLECTED;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected ?string $gateway = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $reference = null;

    /** Why it was not sent: no writer, the platform refused it... */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $collectedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $sentAt = null;

    public function __construct(string $kind, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, \DateTimeImmutable $dueOn, string $regime)
    {
        if (!\in_array($kind, [self::TRANSACTIONS, self::PAYMENTS], true)) {
            throw new \InvalidArgumentException(sprintf('An e-report is of "%s" or "%s", not "%s".', self::TRANSACTIONS, self::PAYMENTS, $kind));
        }
        $this->kind = $kind;
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->dueOn = $dueOn;
        $this->regime = $regime;
        $this->collectedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s %s – %s', $this->kind, $this->startsOn->format('Y-m-d'), $this->endsOn->format('Y-m-d'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getDueOn(): \DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function getRegime(): string
    {
        return $this->regime;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** Collected again: only while it has not been sent. */
    public function collected(array $data): static
    {
        if (self::SENT === $this->state) {
            throw new \LogicException(sprintf('The e-report %s was sent: it is not collected again.', $this));
        }
        $this->data = $data;
        $this->collectedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isSent(): bool
    {
        return self::SENT === $this->state;
    }

    public function sent(string $gateway, string $reference): static
    {
        $this->state = self::SENT;
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->error = null;
        $this->sentAt = new \DateTimeImmutable();

        return $this;
    }

    /** Not sent: why. A period without a writer stays COLLECTED; a platform's refusal makes it FAILED. */
    public function notSent(string $error, bool $failed = false): static
    {
        $this->error = mb_substr($error, 0, 255);
        if ($failed) {
            $this->state = self::FAILED;
        }

        return $this;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCollectedAt(): \DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
}
