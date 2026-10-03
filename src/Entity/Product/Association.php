<?php

namespace Base\Marketplace\Entity\Product;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\AssociationType;
use Doctrine\ORM\Mapping as ORM;

/**
 * One product leading to another on its page: what goes with it (a pairing),
 * what is bought with it (cross-sell), what is better than it (upsell), with
 * a word on why, in each language ("Sur un comté de 24 mois").
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_product_association')]
#[ORM\UniqueConstraint(name: 'marketplace_product_association_unique', columns: ['product_id', 'target_id', 'type'])]
class Association implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'associations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $target = null;

    #[ORM\Column(length: 16, enumType: AssociationType::class)]
    private AssociationType $type = AssociationType::PAIRING;

    /** @var array<string, string> the note by language: ['fr' => '…', 'en' => '…'] */
    #[ORM\Column(type: 'json')]
    private array $notes = [];

    #[ORM\Column]
    private int $position = 0;

    public function __construct(?Product $product = null, ?Product $target = null, AssociationType $type = AssociationType::PAIRING, array $notes = [])
    {
        $this->product = $product;
        $this->target = $target;
        $this->type = $type;
        $this->setNotes($notes);
    }

    public function __toString(): string
    {
        return $this->type->value.' → '.$this->target;
    }

    public function getId(): ?int { return $this->id; }

    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }

    public function getTarget(): ?Product { return $this->target; }
    public function setTarget(?Product $target): self { $this->target = $target; return $this; }

    public function getType(): AssociationType { return $this->type; }
    public function setType(AssociationType $type): self { $this->type = $type; return $this; }

    /** @return array<string, string> */
    public function getNotes(): array { return $this->notes; }

    /** @param array<string, string> $notes */
    public function setNotes(array $notes): self
    {
        $this->notes = array_filter(array_map(fn ($n) => trim((string) $n), $notes), fn ($n) => '' !== $n);

        return $this;
    }

    /** The note in this language, else in the first one written. */
    public function getNote(?string $locale = null): ?string
    {
        $lang = $locale ? substr($locale, 0, 2) : null;

        return ($lang ? ($this->notes[$lang] ?? null) : null) ?? ($this->notes ? reset($this->notes) : null);
    }

    public function setNote(string $locale, ?string $note): self
    {
        $notes = $this->notes;
        $notes[substr($locale, 0, 2)] = (string) $note;

        return $this->setNotes($notes);
    }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
