<?php

namespace Base\Marketplace\Entity\Product;

use Doctrine\ORM\Mapping as ORM;

/**
 * One option of a group: "Bien cuit", "Œuf mollet (+1,50 €)". Its price is
 * what it adds to the product's unit price, in the product's currency and
 * like it before VAT; the product's VAT rate applies to it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_product_option')]
class Option implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OptionGroup::class, inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
    private ?OptionGroup $group = null;

    #[ORM\Column(length: 120)]
    private string $label = '';

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $labels = [];

    /** Added to the unit price: smallest unit, before VAT. */
    #[ORM\Column]
    private int $price = 0;

    /** Ticked when the buyer has not chosen. */
    #[ORM\Column(name: 'preselected')]
    private bool $default = false;

    #[ORM\Column]
    private bool $available = true;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(string $label = '', int $price = 0, bool $default = false)
    {
        $this->label = $label;
        $this->price = max(0, $price);
        $this->default = $default;
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getGroup(): ?OptionGroup { return $this->group; }
    public function setGroup(?OptionGroup $group): self { $this->group = $group; return $this; }

    public function getLabel(?string $locale = null): string
    {
        return ($locale ? ($this->labels[substr($locale, 0, 2)] ?? null) : null) ?? $this->label;
    }

    public function setLabel(string $label): self { $this->label = trim($label); return $this; }
    /** @return array<string, string> */
    public function getLabels(): array { return $this->labels; }
    /** @param array<string, string> $labels */
    public function setLabels(array $labels): self { $this->labels = array_filter(array_map(fn ($l) => trim((string) $l), $labels), fn ($l) => '' !== $l); return $this; }

    public function getPrice(): int { return $this->price; }
    public function setPrice(int $price): self { $this->price = max(0, $price); return $this; }
    public function isDefault(): bool { return $this->default; }
    public function setDefault(bool $default): self { $this->default = $default; return $this; }
    public function isAvailable(): bool { return $this->available; }
    public function setAvailable(bool $available): self { $this->available = $available; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
