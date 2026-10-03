<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\TradeDirection;
use Base\Marketplace\Repository\QuoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A business quote: a professional buyer asks (the quote form), the seller
 * prices lines - a product of the catalogue or a free line, by the lot - with
 * the terms of the trade (which way, which Incoterm, to which country and
 * place, for when), sends it, the buyer accepts it in their account, and it
 * becomes an order paid like any other (Service\QuoteToOrder). An export's
 * order carries no VAT (Pricing\ExportExemption).
 */
#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'marketplace_quote')]
class Quote extends AbstractQuote
{
    /** The company's name as the buyer typed it (a foreign company has no SIRET). */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $companyName = null;

    /** An EU company's VAT number, checked against VIES. */
    #[ORM\Column(length: 20, nullable: true)]
    protected ?string $vatNumber = null;

    #[ORM\Column(length: 16, nullable: true, enumType: TradeDirection::class)]
    protected ?TradeDirection $direction = null;

    #[ORM\Column(length: 3, nullable: true, enumType: Incoterm::class)]
    protected ?Incoterm $incoterm = null;

    /** The named place of the Incoterm: "Le Havre", "Tokyo, Ota-ku warehouse". */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $place = null;

    /** ISO 3166-1 alpha-2: where the goods go (an export, a sale) or come from (an import). */
    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Country]
    protected ?string $country = null;

    /** Where to deliver, as the buyer wrote it. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $deliveryAddress = null;

    /** The volumes asked, in the buyer's words: "300 cases a year", "one pallet". */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $volume = null;

    /** When the buyer wants the goods. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?\DateTimeImmutable $targetDate = null;

    /** The store the order is made in (else the first product's, else marketplace.quotes.store). */
    #[ORM\ManyToOne(targetEntity: Store::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Store $store = null;

    /** @var Collection<int, QuoteLine> */
    #[ORM\OneToMany(targetEntity: QuoteLine::class, mappedBy: 'quote', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $lines;

    /** The order accepting it made. */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Order $order = null;

    public function __construct(string $reference = '')
    {
        parent::__construct($reference);
        $this->lines = new ArrayCollection();
    }

    public function getCompanyName(): ?string { return $this->companyName ?? parent::getCompanyName(); }
    public function setCompanyName(?string $companyName): static { $this->companyName = $companyName ? trim($companyName) : null; return $this; }

    public function getVatNumber(): ?string { return $this->vatNumber; }
    public function setVatNumber(?string $vatNumber): static { $this->vatNumber = $vatNumber ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber)) : null; return $this; }

    public function getDirection(): ?TradeDirection { return $this->direction; }
    public function setDirection(?TradeDirection $direction): static { $this->direction = $direction; return $this; }

    public function getIncoterm(): ?Incoterm { return $this->incoterm; }
    public function setIncoterm(?Incoterm $incoterm): static { $this->incoterm = $incoterm; return $this; }

    public function getPlace(): ?string { return $this->place; }
    public function setPlace(?string $place): static { $this->place = $place; return $this; }

    /** "FOB Le Havre", "DAP Tokyo": the Incoterm and its place, as a contract writes them. */
    public function getTerms(): ?string
    {
        return $this->incoterm ? trim($this->incoterm->value.' '.($this->place ?? '')) : null;
    }

    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): static { $this->country = $country ? strtoupper($country) : null; return $this; }

    public function getDeliveryAddress(): ?string { return $this->deliveryAddress; }
    public function setDeliveryAddress(?string $deliveryAddress): static { $this->deliveryAddress = $deliveryAddress; return $this; }

    public function getVolume(): ?string { return $this->volume; }
    public function setVolume(?string $volume): static { $this->volume = $volume; return $this; }

    public function getTargetDate(): ?\DateTimeImmutable { return $this->targetDate; }
    public function setTargetDate(?\DateTimeImmutable $targetDate): static { $this->targetDate = $targetDate; return $this; }

    public function getStore(): ?Store { return $this->store; }
    public function setStore(?Store $store): static { $this->store = $store; return $this; }

    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this; }

    /** @return Collection<int, QuoteLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(QuoteLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $line->setPosition($this->lines->count());
            $this->lines->add($line);
            $line->setQuote($this);
        }

        return $this;
    }

    public function removeLine(QuoteLine $line): static
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function getSubtotal(): int
    {
        return array_sum($this->lines->map(fn (QuoteLine $line) => $line->getAmount())->toArray());
    }

    /** How many units (bottles) the lines hold together. */
    public function getUnits(): int
    {
        return array_sum($this->lines->map(fn (QuoteLine $line) => $line->getUnits())->toArray());
    }
}
