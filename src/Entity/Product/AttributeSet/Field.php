<?php

namespace Base\Marketplace\Entity\Product\AttributeSet;

use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Marketplace\Entity\Product\AttributeSet;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attribute of a set: the adapter (its code, its translated label, its
 * type, from glitchr/omnibase), its unit, where it comes, whether a product
 * must have it, whether the list filters on it and how (a choice among the
 * values met, or a range for a number).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_attribute_set_field')]
class Field implements \Stringable
{
    public const FILTER_CHOICE = 'choice';
    public const FILTER_RANGE = 'range';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttributeSet::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AttributeSet $attributeSet = null;

    #[ORM\ManyToOne(targetEntity: AbstractAdapter::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AbstractAdapter $adapter = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $required = false;

    #[ORM\Column]
    private bool $filterable = false;

    /** self::FILTER_CHOICE or FILTER_RANGE. */
    #[ORM\Column(length: 16)]
    private string $filter = self::FILTER_CHOICE;

    /** Its unit, as printed after the value: "% vol.", "°C", "ans". */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unit = null;

    /** Shown in the product's sheet (false: kept for filters and feeds only). */
    #[ORM\Column]
    private bool $visible = true;

    public function __construct(?AbstractAdapter $adapter = null, bool $filterable = false, bool $required = false, string $filter = self::FILTER_CHOICE, ?string $unit = null)
    {
        $this->adapter = $adapter;
        $this->unit = $unit;
        $this->filterable = $filterable;
        $this->required = $required;
        $this->filter = $filter;
    }

    public function __toString(): string
    {
        return (string) $this->adapter;
    }

    public function getId(): ?int { return $this->id; }

    public function getAttributeSet(): ?AttributeSet { return $this->attributeSet; }
    public function setAttributeSet(?AttributeSet $attributeSet): self { $this->attributeSet = $attributeSet; return $this; }

    public function getAdapter(): ?AbstractAdapter { return $this->adapter; }
    public function setAdapter(?AbstractAdapter $adapter): self { $this->adapter = $adapter; return $this; }

    /** The attribute's code ("appellation"), the key the filters read in the query string. */
    public function getCode(): ?string { return $this->adapter?->getCode(); }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isRequired(): bool { return $this->required; }
    public function setRequired(bool $required): self { $this->required = $required; return $this; }

    public function isFilterable(): bool { return $this->filterable; }
    public function setFilterable(bool $filterable): self { $this->filterable = $filterable; return $this; }

    public function getFilter(): string { return $this->filter; }
    public function setFilter(string $filter): self { $this->filter = \in_array($filter, [self::FILTER_CHOICE, self::FILTER_RANGE], true) ? $filter : self::FILTER_CHOICE; return $this; }

    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit ?: null; return $this; }

    public function isVisible(): bool { return $this->visible; }
    public function setVisible(bool $visible): self { $this->visible = $visible; return $this; }
}
