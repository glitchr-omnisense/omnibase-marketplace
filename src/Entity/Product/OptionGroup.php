<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Product;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A choice the buyer makes on a product, which is not another product: how
 * it is cooked, what is added, how it is finished. One option of the group
 * (a cooking) or several (extras), between a minimum and a maximum; each
 * option may add to the price. The choices are copied onto the cart line
 * and the order line (OrderItem::$options), their price in the line's.
 *
 * A variant is another reference with its own stock (a size, a vintage); an
 * option is not: it has neither stock nor page.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_product_option_group')]
class OptionGroup implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'optionGroups')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\Column(length: 120)]
    private string $label = '';

    /** @var array<string, string> the label in other languages: ['en' => 'Cooking'] */
    #[ORM\Column(type: 'json')]
    private array $labels = [];

    /** Several options may be taken (extras); false: one (a cooking). */
    #[ORM\Column]
    private bool $multiple = false;

    /** How many must be chosen at least: 0 leaves the group optional. */
    #[ORM\Column]
    private int $minimum = 0;

    /** How many at most (a multiple group); null: as many as there are. */
    #[ORM\Column(nullable: true)]
    private ?int $maximum = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Option> */
    #[ORM\OneToMany(targetEntity: Option::class, mappedBy: 'group', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $options;

    public function __construct(string $label = '', bool $multiple = false, int $minimum = 0, ?int $maximum = null)
    {
        $this->label = $label;
        $this->multiple = $multiple;
        $this->minimum = max(0, $minimum);
        $this->maximum = $maximum;
        $this->options = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }

    /** The label in this language, else the one it was written in. */
    public function getLabel(?string $locale = null): string
    {
        return ($locale ? ($this->labels[substr($locale, 0, 2)] ?? null) : null) ?? $this->label;
    }

    public function setLabel(string $label): self { $this->label = trim($label); return $this; }
    /** @return array<string, string> */
    public function getLabels(): array { return $this->labels; }
    /** @param array<string, string> $labels */
    public function setLabels(array $labels): self { $this->labels = array_filter(array_map(fn ($l) => trim((string) $l), $labels), fn ($l) => '' !== $l); return $this; }

    public function isMultiple(): bool { return $this->multiple; }
    public function setMultiple(bool $multiple): self { $this->multiple = $multiple; return $this; }
    public function getMinimum(): int { return $this->minimum; }
    public function setMinimum(int $minimum): self { $this->minimum = max(0, $minimum); return $this; }
    public function isRequired(): bool { return $this->minimum > 0; }

    /** One for a single choice; the group's own for a multiple one (null: no ceiling). */
    public function getMaximum(): ?int
    {
        return $this->multiple ? $this->maximum : 1;
    }

    public function setMaximum(?int $maximum): self { $this->maximum = null === $maximum ? null : max(1, $maximum); return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection { return $this->options; }

    /** @return list<Option> those that can be chosen now */
    public function getAvailableOptions(): array
    {
        return array_values($this->options->filter(fn (Option $o) => $o->isAvailable())->toArray());
    }

    public function addOption(Option $option): self
    {
        if (!$this->options->contains($option)) {
            $option->setGroup($this);
            if (0 === $option->getPosition()) {
                $option->setPosition($this->options->count());
            }
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): self
    {
        $this->options->removeElement($option);

        return $this;
    }

    /** Shorthand for fixtures: $group->option('Bien cuit'), ->option('Œuf mollet', 150). */
    public function option(string $label, int $price = 0, bool $default = false, array $labels = []): self
    {
        return $this->addOption((new Option($label, $price, $default))->setLabels($labels));
    }
}
