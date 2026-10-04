<?php

namespace Base\Marketplace\Entity\Wishlist;

use Base\Entity\User;
use Base\Marketplace\Enum\WishlistKind;
use Base\Marketplace\Model\WishlistHolderInterface;
use Base\Marketplace\Repository\Wishlist\WishlistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A list of wishes: a wedding's or a birthday's gifts, a birth list, a
 * fund. Its owner keeps it; it may belong to something of the application's
 * (an event: Model\WishlistHolderInterface). Its items are objects from any
 * shop (read through glitchr/omnitrade from their page), products of this
 * shop, or pots; those who give reserve an object or contribute money, paid
 * straight to the owner's payout account.
 *
 * `surprise`: the owner sees that a wish is taken or how much a pot holds,
 * not by whom - until they choose to look (a birth list's default).
 */
#[ORM\Entity(repositoryClass: WishlistRepository::class)]
#[ORM\Table(name: 'marketplace_wishlist')]
#[ORM\Index(columns: ['holder_type', 'holder_id'], name: 'marketplace_wishlist_holder')]
class Wishlist implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** The public address' part: random, unguessable. */
    #[ORM\Column(length: 24, unique: true)]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(name: 'holder_type', length: 64, nullable: true)]
    private ?string $holderType = null;

    #[ORM\Column(name: 'holder_id', length: 64, nullable: true)]
    private ?string $holderId = null;

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(length: 16, enumType: WishlistKind::class)]
    private WishlistKind $kind = WishlistKind::GIFTS;

    #[ORM\Column]
    private bool $surprise = false;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /** Where objects bought elsewhere are sent: shown to whoever reserves one. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shippingAddress = null;

    #[ORM\ManyToOne(targetEntity: PayoutAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PayoutAccount $payoutAccount = null;

    /** Givers may add the platform's fee to their gift, so the owner receives it whole. */
    #[ORM\Column]
    private bool $giverMayCoverFees = true;

    #[ORM\Column]
    private bool $open = true;

    /** @var Collection<int, Item> */
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'wishlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $title, WishlistKind $kind = WishlistKind::GIFTS, ?WishlistHolderInterface $holder = null)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->kind = $kind;
        $this->surprise = WishlistKind::BIRTH === $kind;
        $this->token = bin2hex(random_bytes(12));
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->setHolder($holder);
    }

    public function __toString(): string { return $this->title; }
    public function getId(): ?int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getOwner(): ?User { return $this->owner; }
    public function getHolderType(): ?string { return $this->holderType; }
    public function getHolderId(): ?string { return $this->holderId; }

    public function setHolder(?WishlistHolderInterface $holder): self
    {
        $this->holderType = $holder?->getWishlistHolderType();
        $id = $holder?->getWishlistHolderId();
        $this->holderId = null === $id ? null : (string) $id;

        return $this;
    }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
    public function getKind(): WishlistKind { return $this->kind; }
    public function setKind(WishlistKind $kind): self { $this->kind = $kind; return $this; }
    public function isSurprise(): bool { return $this->surprise; }
    public function setSurprise(bool $surprise): self { $this->surprise = $surprise; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function getShippingAddress(): ?string { return $this->shippingAddress; }
    public function setShippingAddress(?string $shippingAddress): self { $this->shippingAddress = $shippingAddress; return $this; }
    public function getPayoutAccount(): ?PayoutAccount { return $this->payoutAccount; }
    public function setPayoutAccount(?PayoutAccount $payoutAccount): self { $this->payoutAccount = $payoutAccount; return $this; }
    public function isGiverMayCoverFees(): bool { return $this->giverMayCoverFees; }
    public function setGiverMayCoverFees(bool $giverMayCoverFees): self { $this->giverMayCoverFees = $giverMayCoverFees; return $this; }
    public function isOpen(): bool { return $this->open; }
    public function setOpen(bool $open): self { $this->open = $open; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Money may be given: open, and a payout account ready to receive it. */
    public function takesContributions(): bool
    {
        return $this->open && true === $this->payoutAccount?->isReady();
    }

    /** @return Collection<int, Item> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(Item $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setWishlist($this);
        }

        return $this;
    }

    public function removeItem(Item $item): self { $this->items->removeElement($item); return $this; }

    /** Everything given in money, paid. */
    public function getCollected(): int
    {
        $sum = 0;
        foreach ($this->items as $item) {
            $sum += $item->getCollected();
        }

        return $sum;
    }
}
