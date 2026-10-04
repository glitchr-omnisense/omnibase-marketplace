<?php

namespace Base\Marketplace\Entity\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Repository\Order\PickupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * How an order is handed over, when it is not simply posted: collected at
 * the shop on a slot, brought nearby by the shop (a list of postcodes), or
 * sent - and for when, to whom, how far along the shop is. The Order keeps
 * the lines, the prices and the payment; this keeps the rest, one row per
 * order, and a token: /…/{token} follows it without an account.
 *
 * "Fulfilment" names this in some applications and "Supply" is what is made
 * to order by a supplier (Entity\Order\SupplyJob): hence Pickup.
 */
#[ORM\Entity(repositoryClass: PickupRepository::class)]
#[ORM\Table(name: 'marketplace_pickup')]
#[ORM\Index(name: 'marketplace_pickup_token', columns: ['token'])]
#[ORM\Index(name: 'marketplace_pickup_day', columns: ['day'])]
class Pickup
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Order $order;

    /** Orders made together share one: their tracking page shows them together. */
    #[ORM\Column(length: 43)]
    private string $token;

    #[ORM\Column(length: 16, enumType: PickupMode::class)]
    private PickupMode $mode = PickupMode::PICKUP;

    #[ORM\Column(length: 24, enumType: PickupStatus::class)]
    private PickupStatus $status = PickupStatus::CHECKOUT;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $contactName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    /** The day it is for: collected, brought, or handed to the carrier. A calendar day of the shop. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    /** The time wished, "12:00-12:30": indicative. */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $slot = null;

    /** The time the shop gives (its wall clock). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    /** Where it goes (a delivery, a parcel): the street, as written. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** A parcel's tracking number, once it left. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $trackingNumber = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Order $order, \DateTimeImmutable $day, PickupMode $mode = PickupMode::PICKUP, ?string $token = null)
    {
        $this->order = $order;
        $this->day = $day->setTime(0, 0);
        $this->mode = $mode;
        $this->token = $token ?? self::newToken();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** 32 random bytes, URL-safe: nobody guesses a tracking page. */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getToken(): string { return $this->token; }

    public function getMode(): PickupMode { return $this->mode; }
    public function setMode(PickupMode $mode): self { $this->mode = $mode; return $this; }
    public function isPickup(): bool { return PickupMode::PICKUP === $this->mode; }
    public function isDelivery(): bool { return PickupMode::DELIVERY === $this->mode; }
    public function isShipping(): bool { return PickupMode::SHIPPING === $this->mode; }

    public function getStatus(): PickupStatus { return $this->status; }
    public function setStatus(PickupStatus $status): self { $this->status = $status; return $this; }
    public function isAtCheckout(): bool { return PickupStatus::CHECKOUT === $this->status; }
    public function isOpen(): bool { return $this->status->isOpen(); }

    /** @return list<PickupStatus> the steps of its tracking page */
    public function getFlow(): array
    {
        return PickupStatus::flow($this->mode);
    }

    /** Where it stands among its steps; -1 once cancelled, refused or refunded. */
    public function getStep(): int
    {
        $i = array_search($this->status, $this->getFlow(), true);

        return false === $i ? -1 : $i;
    }

    public function getContactName(): ?string { return $this->contactName; }
    public function setContactName(?string $contactName): self { $this->contactName = self::cut($contactName, 120); return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = self::cut($email, 180); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = self::cut($phone, 32); return $this; }

    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function setDay(\DateTimeImmutable $day): self { $this->day = $day->setTime(0, 0); return $this; }
    public function getSlot(): ?string { return $this->slot; }
    public function setSlot(?string $slot): self { $this->slot = self::cut($slot, 16); return $this; }
    public function getReadyAt(): ?\DateTimeImmutable { return $this->readyAt; }
    public function setReadyAt(?\DateTimeImmutable $readyAt): self { $this->readyAt = $readyAt; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = self::cut($address, 1000); return $this; }
    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): self { $this->postcode = self::cut($postcode, 16); return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = self::cut($city, 120); return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = $country ? strtoupper(substr($country, 0, 2)) : null; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = self::cut($note, 2000); return $this; }
    public function getTrackingNumber(): ?string { return $this->trackingNumber; }
    public function setTrackingNumber(?string $trackingNumber): self { $this->trackingNumber = self::cut($trackingNumber, 64); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    private static function cut(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : mb_substr($value, 0, $length);
    }
}
