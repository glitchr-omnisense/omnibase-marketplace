<?php

namespace Base\Marketplace\Entity\Wishlist;

use Base\Entity\User;
use Base\Marketplace\Repository\Wishlist\PayoutAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where the money given to somebody's lists goes: their connected account
 * at the provider (a Stripe Express account), opened through
 * Wishlist\PayoutAccounts. The contributions are paid to it directly
 * (destination charges): the platform never holds them.
 */
#[ORM\Entity(repositoryClass: PayoutAccountRepository::class)]
#[ORM\Table(name: 'marketplace_payout_account')]
#[ORM\UniqueConstraint(name: 'marketplace_payout_account_reference', columns: ['gateway', 'reference'])]
class PayoutAccount implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    /** The omnitrade gateway's name. */
    #[ORM\Column(length: 64)]
    private string $gateway;

    /** The provider's id: "acct_...". */
    #[ORM\Column(length: 128)]
    private string $reference;

    #[ORM\Column(length: 2)]
    private string $country = 'FR';

    #[ORM\Column]
    private bool $detailsSubmitted = false;

    #[ORM\Column]
    private bool $chargesEnabled = false;

    #[ORM\Column]
    private bool $payoutsEnabled = false;

    /** @var list<string> what the provider still asks of its holder */
    #[ORM\Column(type: 'json')]
    private array $requirements = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    public function __construct(User $owner, string $gateway, string $reference, string $country = 'FR')
    {
        $this->owner = $owner;
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->country = strtoupper($country);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->reference; }
    public function getId(): ?int { return $this->id; }
    public function getOwner(): ?User { return $this->owner; }
    public function getGateway(): string { return $this->gateway; }
    public function getReference(): string { return $this->reference; }
    public function getCountry(): string { return $this->country; }
    public function isDetailsSubmitted(): bool { return $this->detailsSubmitted; }
    public function isChargesEnabled(): bool { return $this->chargesEnabled; }
    public function isPayoutsEnabled(): bool { return $this->payoutsEnabled; }
    public function getRequirements(): array { return $this->requirements; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCheckedAt(): ?\DateTimeImmutable { return $this->checkedAt; }

    /** Contributions may be paid to it. */
    public function isReady(): bool { return $this->chargesEnabled && $this->payoutsEnabled; }

    /** @param list<string> $requirements */
    public function update(bool $detailsSubmitted, bool $chargesEnabled, bool $payoutsEnabled, array $requirements = []): self
    {
        $this->detailsSubmitted = $detailsSubmitted;
        $this->chargesEnabled = $chargesEnabled;
        $this->payoutsEnabled = $payoutsEnabled;
        $this->requirements = array_values($requirements);
        $this->checkedAt = new \DateTimeImmutable();

        return $this;
    }
}
