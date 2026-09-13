<?php

namespace Base\Market\Entity\Product;

use Base\Market\Entity\Product;
use Base\Market\Entity\Product\Attribute\Barcode;
use Base\Market\Repository\Product\IdentifierRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\OrderColumn;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IdentifierRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[AssertBase\UniqueEntity(fields: ['barcodes.value'], groups: ['new', 'edit'])]
class Identifier
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-fingerprint'];
    }

    public function __construct()
    {
        $this->barcodes = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'identifiers')]
    protected $product;

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Barcode::class, mappedBy: 'identifier', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[\Base\Database\Attribute\OrderColumn]
    protected $barcodes;

    public function getBarcodes(): Collection
    {
        return $this->barcodes;
    }

    /**
     * @param $std
     * @return Barcode|null
     */
    public function getBarcode($std): ?Barcode
    {
        return $this->barcodes->filter(fn($b) => $b->getStandard() == $std)[0] ?? null;
    }

    public function addBarcode(Barcode $barcode): self
    {
        if (!$this->barcodes->contains($barcode)) {
            $this->barcodes[] = $barcode;
            $barcode->setIdentifier($this);
        }

        return $this;
    }

    public function removeBarcode(Barcode $barcode): self
    {
        if ($this->barcodes->removeElement($barcode)) {
            // set the owning side to null (unless already changed)
            if ($barcode->getIdentifier() === $this) {
                $barcode->setIdentifier(null);
            }
        }

        return $this;
    }
}
