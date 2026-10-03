<?php

namespace Base\Marketplace\Entity\Quote;

use Base\Entity\User;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Service\CompanyRegistry;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What every quote is, whatever it sells: a client (an account, or an
 * e-mail and a name), their company checked against the State's register,
 * what they asked for, the seller's answer, a status, a discount, a date it
 * holds until, a link only they have (the token).
 *
 * Each trade gives it its lines and what accepting makes of it: the
 * marketplace's own Quote (products or free lines, by the lot, an Incoterm,
 * a country) and omnibase/forge's (hours at a rate, credited when paid) map
 * this superclass onto their own table.
 */
#[ORM\MappedSuperclass]
abstract class AbstractQuote implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    protected ?int $id = null;

    /** Q-2026-0042: what the client and the seller call it. */
    #[ORM\Column(length: 32, unique: true)]
    protected string $reference;

    /** Opaque token of the client's link to the quote. */
    #[ORM\Column(length: 43, unique: true)]
    protected string $token;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $client = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank, Assert\Email]
    protected string $email = '';

    /** The client's company, by its SIREN or SIRET, when they gave one. */
    #[ORM\Column(length: 14, nullable: true)]
    protected ?string $siret = null;

    /**
     * What the State's register said of it when the quote was asked
     * (CompanyRegistry): its name, address, whether it trades - or that the
     * register did not answer ("status": unavailable).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $company = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    protected string $contactName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    protected string $title = '';

    /** What the client asked for, in their words. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $request = null;

    /** What the seller answers above the lines. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $message = null;

    #[ORM\Column(length: 16, enumType: QuoteStatus::class)]
    protected QuoteStatus $status = QuoteStatus::REQUESTED;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    protected int $discountPercent = 0;

    #[ORM\Column(length: 3)]
    protected string $currency = 'EUR';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?\DateTimeImmutable $validUntil = null;

    /** The order paying it, once paid. */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $orderReference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(string $reference = '')
    {
        $this->reference = $reference;
        $this->token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->reference.' · '.$this->title;
    }

    /** @return Collection<int, object> its lines, in order */
    abstract public function getLines(): Collection;

    /** Before the discount, in cents. */
    abstract public function getSubtotal(): int;

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }
    public function getToken(): string { return $this->token; }

    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $client): static { $this->client = $client; return $this; }

    public function getSiret(): ?string { return $this->siret; }
    public function setSiret(?string $siret): static { $this->siret = $siret ? CompanyRegistry::normalize($siret) : null; return $this; }

    /** @return array<string, mixed>|null */
    public function getCompany(): ?array { return $this->company; }

    /** Records the register's answer: FOUND with the company, or why not. */
    public function setCompanyCheck(array $lookup): static
    {
        $this->company = ['status' => $lookup['status']] + ($lookup['company']?->toArray() ?? ['checked_at' => (new \DateTimeImmutable())->format(\DATE_ATOM)]);

        return $this;
    }

    /** Records a company checked elsewhere (VIES for a foreign one): its name, its number, the source. */
    public function setCompanyRecord(?array $company): static
    {
        $this->company = $company;

        return $this;
    }

    /** For the back office: verified, closed, unknown, unchecked. */
    public function getCompanyBadge(): ?string
    {
        return CompanyRegistry::companyBadge($this->siret, $this->company);
    }

    /** The company's name as the register has it, else null. */
    public function getCompanyName(): ?string
    {
        return $this->company['name'] ?? null;
    }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getContactName(): string { return $this->contactName; }
    public function setContactName(string $contactName): static { $this->contactName = $contactName; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getRequest(): ?string { return $this->request; }
    public function setRequest(?string $request): static { $this->request = $request; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static { $this->message = $message; return $this; }

    public function getStatus(): QuoteStatus { return $this->status; }
    public function setStatus(QuoteStatus $status): static { $this->status = $status; return $this; }

    public function getDiscountPercent(): int { return $this->discountPercent; }
    public function setDiscountPercent(int $discountPercent): static { $this->discountPercent = max(0, min(100, $discountPercent)); return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeImmutable $validUntil): static { $this->validUntil = $validUntil; return $this; }

    public function isExpired(?\DateTimeImmutable $at = null): bool
    {
        return null !== $this->validUntil && $this->validUntil->setTime(23, 59, 59) < ($at ?? new \DateTimeImmutable());
    }

    /** Whether there is anything to sell in it: a total above zero. */
    public function hasSomethingToSell(): bool
    {
        return $this->getTotal() > 0;
    }

    /** Whether the client may accept it now. */
    public function isAcceptable(): bool
    {
        return $this->status->isOpenToClient() && !$this->isExpired() && $this->hasSomethingToSell();
    }

    public function getDiscountAmount(): int
    {
        return (int) round($this->getSubtotal() * $this->discountPercent / 100);
    }

    /** What the client pays before VAT, in cents. */
    public function getTotal(): int
    {
        return $this->getSubtotal() - $this->getDiscountAmount();
    }

    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $orderReference): static { $this->orderReference = $orderReference; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }

    public function accept(): static
    {
        $this->status = QuoteStatus::ACCEPTED;
        $this->acceptedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Paid: the order that paid it named. */
    public function markPaid(?string $orderReference = null): static
    {
        $this->status = QuoteStatus::PAID;
        $this->orderReference = $orderReference ?? $this->orderReference;

        return $this;
    }
}
