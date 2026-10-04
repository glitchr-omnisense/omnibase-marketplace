<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Repository\UsageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A counted grant used: one of the "events.major": 1 of a plan taken by
 * this event, 3 of its 5 exports made. Releasing it (the event deleted)
 * gives the unit back. What used it is named the way an Entitlement names
 * its resource.
 */
#[ORM\Entity(repositoryClass: UsageRepository::class)]
#[ORM\Table(name: 'marketplace_usage')]
#[ORM\Index(columns: ['entitlement_id', 'grant_key'], name: 'marketplace_usage_key')]
class Usage
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entitlement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entitlement $entitlement;

    #[ORM\Column(name: 'grant_key', length: 64)]
    private string $key;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resourceType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $usedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function __construct(Entitlement $entitlement, string $key, int $quantity = 1, ?string $resourceType = null, int|string|null $resourceId = null)
    {
        $this->entitlement = $entitlement;
        $this->key = $key;
        $this->quantity = max(1, $quantity);
        $this->resourceType = $resourceType;
        $this->resourceId = null === $resourceId ? null : (string) $resourceId;
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEntitlement(): Entitlement { return $this->entitlement; }
    public function getKey(): string { return $this->key; }
    public function getQuantity(): int { return $this->quantity; }
    public function getResourceType(): ?string { return $this->resourceType; }
    public function getResourceId(): ?string { return $this->resourceId; }
    public function getUsedAt(): \DateTimeImmutable { return $this->usedAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function isReleased(): bool { return null !== $this->releasedAt; }
    public function release(): self { $this->releasedAt ??= new \DateTimeImmutable(); return $this; }
}
