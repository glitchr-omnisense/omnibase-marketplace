<?php

namespace Base\Marketplace\Entity\Wishlist;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ContributionStatus;
use Base\Marketplace\Enum\WishlistItemKind;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A wish: an object from any shop - its page's address, what an omnitrade
 * gateway read there (title, picture, price, merchant, the day the price
 * was read), the affiliate link when the shop has a programme -, a product
 * of this shop, or a pot. `quantity` of an object may be reserved in all;
 * a SHARE's price (times its quantity) is what contributions add up to; a
 * FUND's price, when set, is a goal shown, not a ceiling.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_wishlist_item')]
class Item implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Wishlist::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Wishlist $wishlist = null;

    #[ORM\Column(length: 16, enumType: WishlistItemKind::class)]
    private WishlistItemKind $kind = WishlistItemKind::OBJECT;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $imageUrl = null;

    /** In minor units; null: not known (an object whose page gave none). */
    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column]
    private int $quantity = 1;

    /** One of this shop's products, when the wish is one. */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    /** The omnitrade gateway that read it ("web", "amazon"), and its reference there. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $gateway = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $productReference = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $affiliateUrl = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $merchant = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $priceFetchedAt = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $hidden = false;

    /** @var Collection<int, Reservation> */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'item', cascade: ['remove'])]
    private Collection $reservations;

    /** @var Collection<int, Contribution> */
    #[ORM\OneToMany(targetEntity: Contribution::class, mappedBy: 'item')]
    private Collection $contributions;

    public function __construct(string $title = '', WishlistItemKind $kind = WishlistItemKind::OBJECT, ?int $price = null, string $currency = 'EUR')
    {
        $this->title = $title;
        $this->kind = $kind;
        $this->price = $price;
        $this->currency = strtoupper($currency);
        $this->reservations = new ArrayCollection();
        $this->contributions = new ArrayCollection();
    }

    public function __toString(): string { return $this->title; }
    public function getId(): ?int { return $this->id; }
    public function getWishlist(): ?Wishlist { return $this->wishlist; }
    public function setWishlist(?Wishlist $wishlist): self { $this->wishlist = $wishlist; return $this; }
    public function getKind(): WishlistItemKind { return $this->kind; }
    public function setKind(WishlistItemKind $kind): self { $this->kind = $kind; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = mb_substr($title, 0, 255); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl): self { $this->imageUrl = $imageUrl; return $this; }
    public function getPrice(): ?int { return $this->price; }
    public function setPrice(?int $price, ?\DateTimeImmutable $fetchedAt = null): self { $this->price = $price; $this->priceFetchedAt = $fetchedAt ?? $this->priceFetchedAt; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = max(1, $quantity); return $this; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getGateway(): ?string { return $this->gateway; }
    public function getProductReference(): ?string { return $this->productReference; }
    public function setSource(?string $gateway, ?string $productReference): self { $this->gateway = $gateway; $this->productReference = $productReference; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $url): self { $this->url = $url; return $this; }
    public function getAffiliateUrl(): ?string { return $this->affiliateUrl; }
    public function setAffiliateUrl(?string $affiliateUrl): self { $this->affiliateUrl = $affiliateUrl; return $this; }
    /** Where a giver is sent to buy it: the affiliate link when there is one. */
    public function getBuyUrl(): ?string { return $this->affiliateUrl ?? $this->url; }
    public function getMerchant(): ?string { return $this->merchant; }
    public function setMerchant(?string $merchant): self { $this->merchant = $merchant; return $this; }
    public function getPriceFetchedAt(): ?\DateTimeImmutable { return $this->priceFetchedAt; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
    public function isHidden(): bool { return $this->hidden; }
    public function setHidden(bool $hidden): self { $this->hidden = $hidden; return $this; }
    /** @return Collection<int, Reservation> */
    public function getReservations(): Collection { return $this->reservations; }
    /** @return Collection<int, Contribution> */
    public function getContributions(): Collection { return $this->contributions; }

    /** How many are promised or bought. */
    public function getReserved(?\DateTimeImmutable $at = null): int
    {
        $reserved = 0;
        foreach ($this->reservations as $reservation) {
            if ($reservation->holds($at)) {
                $reserved += $reservation->getQuantity();
            }
        }

        return $reserved;
    }

    /** How many are still to be offered (an object). */
    public function getAvailable(?\DateTimeImmutable $at = null): int
    {
        return max(0, $this->quantity - $this->getReserved($at));
    }

    /** Money given, paid (the givers' gifts, before the platform's fee). */
    public function getCollected(): int
    {
        $sum = 0;
        foreach ($this->contributions as $contribution) {
            if (ContributionStatus::PAID === $contribution->getStatus()) {
                $sum += $contribution->getAmount();
            }
        }

        return $sum;
    }

    /** What a SHARE still lacks; null for a pot without ceiling or an object. */
    public function getRemaining(): ?int
    {
        if (WishlistItemKind::SHARE !== $this->kind || null === $this->price) {
            return null;
        }

        return max(0, $this->price * $this->quantity - $this->getCollected());
    }

    /** Nothing more to give: every object reserved, or the share's price reached. */
    public function isFulfilled(): bool
    {
        return match ($this->kind) {
            WishlistItemKind::OBJECT => 0 === $this->getAvailable(),
            WishlistItemKind::SHARE => 0 === $this->getRemaining(),
            WishlistItemKind::FUND => false,
        };
    }
}
