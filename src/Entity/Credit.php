<?php

namespace Base\Marketplace\Entity;

use Base\Entity\User;
use Base\Marketplace\Repository\CreditRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A line of somebody's credit ledger, of one kind ("ai": answers of an
 * assistant, "stationery": cents of print, "hour": hours of work...): a
 * positive quantity credits (a pack bought, a plan's allowance, a gift), a
 * negative one spends. The balance is the sum (Service\Credits); a credit
 * with an expiry stops counting then, what was spent before stays spent.
 *
 * Generic on purpose: a bundle keeps its units here under its own kind
 * rather than in a ledger of its own.
 */
#[ORM\Entity(repositoryClass: CreditRepository::class)]
#[ORM\Table(name: 'marketplace_credit')]
#[ORM\Index(columns: ['holder_id', 'kind'], name: 'marketplace_credit_holder')]
class Credit
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'holder_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $holder = null;

    #[ORM\Column(length: 32)]
    private string $kind;

    #[ORM\Column]
    private int $quantity;

    /** Why: "order ABC-1234", "plan-d", "answer to a guest", a refund. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    /** What it was spent on (or granted for), named as an Entitlement names its resource. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resourceType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resourceId = null;

    /** Of a grant: when what is left of it lapses. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $holder, string $kind, int $quantity, ?string $reason = null, ?\DateTimeImmutable $expiresAt = null)
    {
        if (0 === $quantity) {
            throw new \InvalidArgumentException('A credit line moves the balance.');
        }
        $this->holder = $holder;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->reason = $reason;
        $this->expiresAt = $quantity > 0 ? $expiresAt : null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getHolder(): ?User { return $this->holder; }
    public function getKind(): string { return $this->kind; }
    public function getQuantity(): int { return $this->quantity; }
    public function isGrant(): bool { return $this->quantity > 0; }
    public function getReason(): ?string { return $this->reason; }
    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $orderReference): self { $this->orderReference = $orderReference; return $this; }
    public function getResourceType(): ?string { return $this->resourceType; }
    public function getResourceId(): ?string { return $this->resourceId; }

    public function setResource(?string $type, int|string|null $id): self
    {
        $this->resourceType = $type;
        $this->resourceId = null === $id ? null : (string) $id;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
