<?php

namespace Base\Marketplace\Entity;

use Base\Entity\User;
use Base\Marketplace\Entity\Order\Subscription;
use Base\Marketplace\Repository\EntitlementRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A right somebody holds: what a plan bought gives (its grants: limits,
 * switches, rates), from when until when, and where it comes from (the
 * product, the order's reference, the subscription that carries it).
 *
 * Generic on purpose - any bundle hangs its own rights here rather than
 * keeping a table of its own:
 *
 *   - a plan of a platform: code "plan-b", grants {"events.major": 1,
 *     "guests": 150, "list": true}, no resource;
 *   - an access to one thing (a course, a download): code "course", the
 *     resource named (resourceType "classroom_course", resourceId "42"),
 *     no grants needed - holding it is the right;
 *   - anything granted by hand (a gift, a partner): no product, no order.
 *
 * Counted grants are consumed through Entity\Usage; Service\Entitlements
 * answers "may they?" and "how many are left?".
 */
#[ORM\Entity(repositoryClass: EntitlementRepository::class)]
#[ORM\Table(name: 'marketplace_entitlement')]
#[ORM\Index(columns: ['holder_id', 'code'], name: 'marketplace_entitlement_holder')]
#[ORM\Index(columns: ['resource_type', 'resource_id'], name: 'marketplace_entitlement_resource')]
class Entitlement implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'holder_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $holder = null;

    /** What is granted, as the application names it: a plan's slug, "course", "hours". */
    #[ORM\Column(length: 96)]
    private string $code = '';

    /** @var array<string, int|float|bool|string|null> limits (integers), switches (booleans), rates, values */
    #[ORM\Column(type: 'json')]
    private array $grants = [];

    /** How entitlements compare (PlanTerms::$level): the highest one answers a question about a value. */
    #[ORM\Column]
    private int $level = 0;

    /** The one thing it is for, when it is for one: a kind the application names and its id. */
    #[ORM\Column(name: 'resource_type', length: 64, nullable: true)]
    private ?string $resourceType = null;

    #[ORM\Column(name: 'resource_id', length: 64, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startsAt;

    /** null: for good - or, carried by a subscription, as long as it runs. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Set once its end was announced (Event\EntitlementEndedEvent): told once. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** Set once its coming end was announced (Event\EntitlementEndingEvent). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remindedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, int|float|bool|string|null> $grants */
    public function __construct(User $holder, string $code, array $grants = [], ?\DateTimeImmutable $expiresAt = null, ?\DateTimeImmutable $startsAt = null)
    {
        $this->holder = $holder;
        $this->code = $code;
        $this->grants = $grants;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt = $startsAt ?? $this->createdAt;
    }

    public function __toString(): string { return $this->code; }
    public function getId(): ?int { return $this->id; }
    public function getHolder(): ?User { return $this->holder; }
    public function getCode(): string { return $this->code; }
    public function getGrants(): array { return $this->grants; }
    public function setGrants(array $grants): self { $this->grants = $grants; return $this; }
    public function getGrant(string $key, mixed $default = null): mixed { return $this->grants[$key] ?? $default; }
    public function getLevel(): int { return $this->level; }
    public function setLevel(int $level): self { $this->level = $level; return $this; }
    public function getResourceType(): ?string { return $this->resourceType; }
    public function getResourceId(): ?string { return $this->resourceId; }

    public function setResource(?string $type, int|string|null $id): self
    {
        $this->resourceType = $type;
        $this->resourceId = null === $id ? null : (string) $id;

        return $this;
    }

    public function isFor(?string $type, int|string|null $id): bool
    {
        return $this->resourceType === $type && $this->resourceId === (null === $id ? null : (string) $id);
    }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $orderReference): self { $this->orderReference = $orderReference; return $this; }
    public function getSubscription(): ?Subscription { return $this->subscription; }
    public function setSubscription(?Subscription $subscription): self { $this->subscription = $subscription; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function revoke(?\DateTimeImmutable $at = null): self { $this->revokedAt ??= $at ?? new \DateTimeImmutable(); return $this; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function markEnded(): self { $this->endedAt ??= new \DateTimeImmutable(); return $this; }
    public function getRemindedAt(): ?\DateTimeImmutable { return $this->remindedAt; }
    public function markReminded(): self { $this->remindedAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Held at that moment: started, not revoked, not expired, its subscription (if any) running. */
    public function isActive(?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();
        if (null !== $this->revokedAt && $this->revokedAt <= $at) {
            return false;
        }
        if ($this->startsAt > $at || (null !== $this->expiresAt && $this->expiresAt <= $at)) {
            return false;
        }

        return null === $this->subscription || $this->subscription->isRunning();
    }
}
