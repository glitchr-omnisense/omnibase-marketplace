<?php

namespace Base\Market\Entity\Product\Attribute;

use Base\Market\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Market\Entity\Product\Identifier;
use Base\Market\Repository\Product\Attribute\BarcodeRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Entity\Layout\Attribute\Common\AbstractAttribute;
use Base\Validator\Constraints as AssertBase;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * @ORM\Entity(repositoryClass=BarcodeRepository::class)
 *
 * @ORM\InheritanceType( "JOINED" )
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @AssertBase\UniqueEntity(fields={"value"}, groups={"new", "edit"})
 *
 * @ORM\DiscriminatorColumn( name = "context", type = "string" )
 *
 * @DiscriminatorEntry(value="barcode")
 */
class Barcode extends AbstractAttribute
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-tag'];
    }

    public function __construct(BarcodeAdapter $adapter, mixed $value = null)
    {
        parent::__construct($adapter);
        $this->setValue($value ?? Uuid::v4());
    }

    public function get(?string $locale = null): mixed
    {
        return $this->getValue();
    }

    public function set(...$args): self
    {
        return !empty($args) ? $this->setValue($args[0]) : $this;
    }

    public function resolve(?string $locale = null): mixed
    {
        return $this->adapter->resolve($this->getValue()) ?? null;
    }

    /**
     * @ORM\ManyToOne(targetEntity=Identifier::class, inversedBy="barcodes")
     *
     * @ORM\JoinColumn(nullable=false)
     */
    protected $identifier;

    public function getIdentifier(): ?Identifier
    {
        return $this->identifier;
    }

    public function setIdentifier(?Identifier $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    protected $value;

    public function getValue(): ?string
    {
        $value = explode(' ', $this->value, 2)[1] ?? $this->value;

        return is_uuidv4($value) ? null : $value;
    }

    /**
     * @param $value
     * @return $this
     */
    /**
     * @param $value
     * @return $this
     */
    public function setValue($value)
    {
        $this->value = str_replace(' ', '', $value ?: Uuid::v4());
        if ($this->value) {
            $this->value = trim($this->getStandard() . ' ' . $this->value);
        }

        return $this;
    }

    public function getAdapter(): ?BarcodeAdapter
    {
        $adapter = parent::getAdapter();

        return $adapter instanceof BarcodeAdapter ? $adapter : null;
    }

    public function setAdapter(?AbstractAdapter $adapter): self
    {
        if (!$adapter instanceof BarcodeAdapter) {
            throw new \InvalidArgumentException('Adapter provided "' . (is_object($adapter) ? get_class($adapter) : 'NULL') . '". Expecting an adapter "' . BarcodeAdapter::class . '"');
        }

        parent::setAdapter($adapter);
        $array = explode(' ', $this->value);

        $value = end($array);
        $this->setValue($value);

        return $this;
    }

    public function getStandard(): ?string
    {
        return $this->getAdapter()?->getStandard();
    }
}
