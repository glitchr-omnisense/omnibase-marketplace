<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Product\AttributeSet\Field;
use Base\Marketplace\Repository\Product\AttributeSetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The typed sheet of a kind of product, without code: which attributes a
 * product of this taxon has (glitchr/omnibase's attribute adapters: a text,
 * a number with its unit, a choice, a colour...), in which order, which are
 * required and which the list filters on.
 *
 * A wine's: appellation, region, grapes, colour, alcohol (% vol.), serving
 * temperature (°C), keeping (years), tasting notes - declared in fixtures or
 * in the back office, and its vintages and formats are Variants. A taxon
 * without a set of its own takes its parent's; a set with no taxon is every
 * product's.
 */
#[ORM\Entity(repositoryClass: AttributeSetRepository::class)]
#[ORM\Table(name: 'marketplace_attribute_set')]
class AttributeSet implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $name = '';

    /** The kind of product it describes; null: every product. */
    #[ORM\OneToOne(targetEntity: Taxon::class)]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'CASCADE')]
    private ?Taxon $taxon = null;

    /** @var Collection<int, Field> */
    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'attributeSet', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $fields;

    public function __construct(string $name = '', ?Taxon $taxon = null)
    {
        $this->name = $name;
        $this->taxon = $taxon;
        $this->fields = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getTaxon(): ?Taxon { return $this->taxon; }
    public function setTaxon(?Taxon $taxon): self { $this->taxon = $taxon; return $this; }

    /** @return Collection<int, Field> */
    public function getFields(): Collection { return $this->fields; }

    public function addField(Field $field): self
    {
        if (!$this->fields->contains($field)) {
            $field->setPosition($this->fields->count());
            $field->setAttributeSet($this);
            $this->fields->add($field);
        }

        return $this;
    }

    public function removeField(Field $field): self
    {
        $this->fields->removeElement($field);

        return $this;
    }

    /** @return list<Field> those the list filters on */
    public function getFilterableFields(): array
    {
        return array_values($this->fields->filter(fn (Field $field) => $field->isFilterable())->toArray());
    }

    /** The field of this attribute code, if the set has one. */
    public function getField(string $code): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->getCode() === $code) {
                return $field;
            }
        }

        return null;
    }
}
