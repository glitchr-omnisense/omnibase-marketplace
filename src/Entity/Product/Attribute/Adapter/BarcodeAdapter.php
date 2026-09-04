<?php

namespace Base\Market\Entity\Product\Attribute\Adapter;

use Base\Market\Repository\Product\Attribute\Adapter\BarcodeAdapterRepository;
use Base\Database\Annotation\Cache;
use Base\Database\Annotation\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * @ORM\Entity(repositoryClass=BarcodeAdapterRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 *
 * @DiscriminatorEntry( value = "barcode" )
 */
class BarcodeAdapter extends AbstractAdapter implements IconizeInterface
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-barcode'];
    }

    public static function getType(): string
    {
        return TextType::class;
    }

    public function getOptions(): array
    {
        return [];
    }

    public function resolve(mixed $value): mixed
    {
        return $value;
    }

    /**
     * @ORM\Column(type="barcode")
     */
    protected $standard;

    /**
     * @return mixed
     */
    public function getStandard()
    {
        return $this->standard;
    }

    /**
     * @param $standard
     * @return $this
     */
    /**
     * @param $standard
     * @return $this
     */
    public function setStandard($standard): self
    {
        $this->standard = $standard;

        return $this;
    }
}
