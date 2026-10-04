<?php

namespace Base\Marketplace\Entity\Wishlist;

use Base\Marketplace\Enum\ContributionStatus;
use Base\Marketplace\Repository\Wishlist\ContributionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Money given to a pot or towards a shared object: the gift (`amount`), the
 * platform's fee on it (`fee`), and what the giver is charged - the gift,
 * plus the fee when they chose to cover it. Paid to the list's payout
 * account as a destination charge; the provider's reference is kept to
 * recognise its webhook.
 */
#[ORM\Entity(repositoryClass: ContributionRepository::class)]
#[ORM\Table(name: 'marketplace_wishlist_contribution')]
#[ORM\Index(columns: ['provider_reference'], name: 'marketplace_wishlist_contribution_provider')]
class Contribution
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Item::class, inversedBy: 'contributions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    /** Ours: "CTB-3F9A2C71", the payment's reference at the provider. */
    #[ORM\Column(length: 24, unique: true)]
    private string $reference;

    #[ORM\Column]
    private int $amount;

    #[ORM\Column]
    private int $fee = 0;

    #[ORM\Column]
    private bool $feeCovered = false;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $giverReference = null;

    #[ORM\Column(length: 16, enumType: ContributionStatus::class)]
    private ContributionStatus $status = ContributionStatus::PENDING;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $gateway = null;

    #[ORM\Column(name: 'provider_reference', length: 128, nullable: true)]
    private ?string $providerReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** Where the giver goes to pay; not kept. */
    private ?string $redirectUrl = null;

    public function __construct(Item $item, int $amount, string $name, ?string $email = null)
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('A contribution is positive.');
        }
        $this->item = $item;
        $this->amount = $amount;
        $this->currency = $item->getCurrency();
        $this->name = $name;
        $this->email = $email;
        $this->reference = 'CTB-'.strtoupper(bin2hex(random_bytes(5)));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): Item { return $this->item; }
    public function getWishlist(): ?Wishlist { return $this->item->getWishlist(); }
    public function getReference(): string { return $this->reference; }
    /** The gift, in minor units. */
    public function getAmount(): int { return $this->amount; }
    public function getFee(): int { return $this->fee; }
    public function isFeeCovered(): bool { return $this->feeCovered; }

    public function setFee(int $fee, bool $covered): self
    {
        $this->fee = max(0, $fee);
        $this->feeCovered = $covered;

        return $this;
    }

    /** What the giver pays: the gift, plus the fee when they cover it. */
    public function getCharged(): int { return $this->amount + ($this->feeCovered ? $this->fee : 0); }
    /** What the owner receives: the gift, less the fee unless the giver covered it. */
    public function getReceived(): int { return $this->getCharged() - $this->fee; }
    public function getCurrency(): string { return $this->currency; }
    public function getName(): string { return $this->name; }
    public function getEmail(): ?string { return $this->email; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
    public function getGiverReference(): ?string { return $this->giverReference; }
    public function setGiverReference(?string $giverReference): self { $this->giverReference = $giverReference; return $this; }
    public function getStatus(): ContributionStatus { return $this->status; }
    public function isPaid(): bool { return ContributionStatus::PAID === $this->status; }
    public function getGateway(): ?string { return $this->gateway; }
    public function getProviderReference(): ?string { return $this->providerReference; }
    public function setProvider(string $gateway, ?string $reference): self { $this->gateway = $gateway; $this->providerReference = $reference; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }

    public function markPaid(): self
    {
        $this->status = ContributionStatus::PAID;
        $this->paidAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function markCancelled(): self
    {
        if (ContributionStatus::PENDING === $this->status) {
            $this->status = ContributionStatus::CANCELLED;
        }

        return $this;
    }

    public function markRefunded(): self { $this->status = ContributionStatus::REFUNDED; return $this; }
    public function getRedirectUrl(): ?string { return $this->redirectUrl; }
    public function setRedirectUrl(?string $redirectUrl): self { $this->redirectUrl = $redirectUrl; return $this; }
}
