<?php

namespace Base\Marketplace\Entity\Sales\Referral;

use Base\Entity\User;
use Base\Marketplace\Enum\ReferralStatus;
use Base\Marketplace\Repository\Sales\Referral\ReferralRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody who came through a member's code: the referee (one referral
 * each, for good), where it stands, the order that qualified it, and why it
 * was refused when it was.
 */
#[ORM\Entity(repositoryClass: ReferralRepository::class)]
#[ORM\Table(name: 'marketplace_referral')]
class Referral
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReferralCode::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReferralCode $code;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $referee = null;

    #[ORM\Column(length: 16, enumType: ReferralStatus::class)]
    private ReferralStatus $status = ReferralStatus::PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    /** What the order was paid with, as the provider identifies it (a card's fingerprint), when known. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $fingerprint = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $rejection = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $qualifiedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rewardedAt = null;

    public function __construct(ReferralCode $code, User $referee)
    {
        $this->code = $code;
        $this->referee = $referee;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): ReferralCode { return $this->code; }
    public function getReferrer(): ?User { return $this->code->getOwner(); }
    public function getReferee(): ?User { return $this->referee; }
    public function getStatus(): ReferralStatus { return $this->status; }
    public function getOrderReference(): ?string { return $this->orderReference; }
    public function getFingerprint(): ?string { return $this->fingerprint; }
    public function getRejection(): ?string { return $this->rejection; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getQualifiedAt(): ?\DateTimeImmutable { return $this->qualifiedAt; }
    public function getRewardedAt(): ?\DateTimeImmutable { return $this->rewardedAt; }

    public function qualify(string $orderReference, ?string $fingerprint = null, ?\DateTimeImmutable $at = null): self
    {
        $this->status = ReferralStatus::QUALIFIED;
        $this->orderReference = $orderReference;
        $this->fingerprint = $fingerprint;
        $this->qualifiedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function markRewarded(): self
    {
        $this->status = ReferralStatus::REWARDED;
        $this->rewardedAt = new \DateTimeImmutable();

        return $this;
    }

    /** self, email, card, cap, refunded, review... */
    public function reject(string $reason): self
    {
        $this->status = ReferralStatus::REJECTED;
        $this->rejection = $reason;

        return $this;
    }
}
