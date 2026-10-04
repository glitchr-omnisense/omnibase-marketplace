<?php

namespace Base\Marketplace\Entity\Sales\Referral;

use Base\Entity\User;
use Base\Marketplace\Entity\Sales\Discount\Coupon;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a referral gave: the referee's welcome coupon, the referrer's coupon
 * or credits once the referee's first order held.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_referral_reward')]
class Reward
{
    public const REFEREE = 'referee';
    public const REFERRER = 'referrer';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Referral::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Referral $referral;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $beneficiary = null;

    /** referee or referrer. */
    #[ORM\Column(length: 16)]
    private string $role;

    #[ORM\ManyToOne(targetEntity: Coupon::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Coupon $coupon = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $creditKind = null;

    #[ORM\Column(nullable: true)]
    private ?int $creditQuantity = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $grantedAt;

    public function __construct(Referral $referral, User $beneficiary, string $role)
    {
        $this->referral = $referral;
        $this->beneficiary = $beneficiary;
        $this->role = $role;
        $this->grantedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReferral(): Referral { return $this->referral; }
    public function getBeneficiary(): ?User { return $this->beneficiary; }
    public function getRole(): string { return $this->role; }
    public function getCoupon(): ?Coupon { return $this->coupon; }
    public function setCoupon(?Coupon $coupon): self { $this->coupon = $coupon; return $this; }
    public function getCreditKind(): ?string { return $this->creditKind; }
    public function getCreditQuantity(): ?int { return $this->creditQuantity; }
    public function setCredit(string $kind, int $quantity): self { $this->creditKind = $kind; $this->creditQuantity = $quantity; return $this; }
    public function getGrantedAt(): \DateTimeImmutable { return $this->grantedAt; }
}
