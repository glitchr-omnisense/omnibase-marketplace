<?php

namespace Base\Marketplace\Entity\Quote;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of a quote: a product of the catalogue or a free line (the
 * transport, a label printed for the importer), a number of lots, how many
 * units a lot holds and the price of one lot - "20 cases of 6 at 162 € the
 * case". A line sold by the unit has lots of 1.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_quote_line')]
class QuoteLine implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    /** null: a free line. */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\Column(length: 255)]
    private string $label = '';

    /** How many lots. */
    #[ORM\Column]
    #[Assert\Positive]
    private int $quantity = 1;

    /** How many units one lot holds (6, 12; 1 by the unit). */
    #[ORM\Column]
    #[Assert\Positive]
    private int $lotSize = 1;

    /** The price of one lot before VAT, in cents. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $lotPrice = 0;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(?Product $product = null, int $quantity = 1, int $lotPrice = 0, int $lotSize = 1, ?string $label = null)
    {
        $this->product = $product;
        $this->quantity = max(1, $quantity);
        $this->lotPrice = max(0, $lotPrice);
        $this->lotSize = max(1, $lotSize);
        $this->label = $label ?? ($product ? (string) $product : '');
    }

    public function __toString(): string
    {
        return '' !== $this->label ? $this->label : (string) $this->product;
    }

    public function getId(): ?int { return $this->id; }

    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): self { $this->quote = $quote; return $this; }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        if ($product && '' === $this->label) {
            $this->label = (string) $product;
        }

        return $this;
    }

    public function isFree(): bool
    {
        return null === $this->product;
    }

    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = (string) $label; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = max(1, $quantity); return $this; }

    public function getLotSize(): int { return $this->lotSize; }
    public function setLotSize(int $lotSize): self { $this->lotSize = max(1, $lotSize); return $this; }

    public function getLotPrice(): int { return $this->lotPrice; }
    public function setLotPrice(int $lotPrice): self { $this->lotPrice = max(0, $lotPrice); return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** How many units the line holds. */
    public function getUnits(): int
    {
        return $this->quantity * $this->lotSize;
    }

    /** The line's amount before VAT, in cents. */
    public function getAmount(): int
    {
        return $this->quantity * $this->lotPrice;
    }
}
