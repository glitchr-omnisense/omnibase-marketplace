<?php

namespace Base\Marketplace\Entity\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Repository\Order\SupplyJobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Part of a paid order handed to a supplier to be made and sent: the lines
 * for one recipient at one supplier, the supplier's reference for it, where
 * it stands and, once it left, its parcel's tracking.
 */
#[ORM\Entity(repositoryClass: SupplyJobRepository::class)]
#[ORM\Table(name: 'marketplace_supply_job')]
#[ORM\Index(columns: ['supplier', 'supplier_reference'], name: 'marketplace_supply_job_supplier')]
class SupplyJob implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 32)]
    private string $supplier;

    /** Ours: the order's reference and a rank ("CMD-1042-2"). */
    #[ORM\Column(length: 80, unique: true)]
    private string $reference;

    #[ORM\Column(name: 'supplier_reference', length: 128, nullable: true)]
    private ?string $supplierReference = null;

    #[ORM\Column(length: 16, enumType: SupplyStatus::class)]
    private SupplyStatus $status = SupplyStatus::SUBMITTED;

    /** @var list<array{item: int|string, product: string, quantity: int, title: ?string, files: list<string>, options: array}> */
    #[ORM\Column(type: 'json')]
    private array $lines = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $recipient = [];

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $trackingUrl = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $carrier = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Order $order, string $supplier, string $reference, array $lines, array $recipient)
    {
        $this->order = $order;
        $this->supplier = $supplier;
        $this->reference = $reference;
        $this->lines = $lines;
        $this->recipient = $recipient;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->reference; }
    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getSupplier(): string { return $this->supplier; }
    public function getReference(): string { return $this->reference; }
    public function getSupplierReference(): ?string { return $this->supplierReference; }
    public function getStatus(): SupplyStatus { return $this->status; }
    public function getLines(): array { return $this->lines; }
    public function getRecipient(): array { return $this->recipient; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function getTrackingUrl(): ?string { return $this->trackingUrl; }
    public function getCarrier(): ?string { return $this->carrier; }
    public function getMessage(): ?string { return $this->message; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** What the supplier says, kept; true when something moved. */
    public function update(SupplyStatus $status, ?string $supplierReference = null, ?string $trackingNumber = null, ?string $trackingUrl = null, ?string $carrier = null, ?string $message = null): bool
    {
        $before = [$this->status, $this->supplierReference, $this->trackingNumber, $this->trackingUrl];
        $this->status = $status;
        $this->supplierReference = $supplierReference ?: $this->supplierReference;
        $this->trackingNumber = $trackingNumber ?? $this->trackingNumber;
        $this->trackingUrl = $trackingUrl ?? $this->trackingUrl;
        $this->carrier = $carrier ?? $this->carrier;
        $this->message = $message ?? $this->message;
        $moved = $before !== [$this->status, $this->supplierReference, $this->trackingNumber, $this->trackingUrl];
        if ($moved) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $moved;
    }
}
