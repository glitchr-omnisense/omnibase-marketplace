<?php

namespace Base\Marketplace\Entity\Wishlist;

use Base\Marketplace\Enum\ReservationStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody promises an object of a list: it leaves the list for the others,
 * so nothing is given twice. Held until it expires (30 days by default) -
 * unless confirmed as bought - or cancelled with the link sent to its
 * author (only the link's hash is kept).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_wishlist_reservation')]
class Reservation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Item::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    /** Who it is for the application (a household's id...), when it knows. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $giverReference = null;

    #[ORM\Column(length: 16, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::HELD;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Item $item, int $quantity, string $name, ?string $email, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->item = $item;
        $this->quantity = max(1, $quantity);
        $this->name = $name;
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getItem(): Item { return $this->item; }
    public function getQuantity(): int { return $this->quantity; }
    public function getName(): string { return $this->name; }
    public function getEmail(): ?string { return $this->email; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
    public function getGiverReference(): ?string { return $this->giverReference; }
    public function setGiverReference(?string $giverReference): self { $this->giverReference = $giverReference; return $this; }
    public function getStatus(): ReservationStatus { return $this->status; }
    public function setStatus(ReservationStatus $status): self { $this->status = $status; return $this; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }

    /** It keeps the object off the list at that moment. */
    public function holds(?\DateTimeImmutable $at = null): bool
    {
        if (ReservationStatus::CONFIRMED === $this->status) {
            return true;
        }

        return ReservationStatus::HELD === $this->status && $this->expiresAt > ($at ?? new \DateTimeImmutable());
    }
}
