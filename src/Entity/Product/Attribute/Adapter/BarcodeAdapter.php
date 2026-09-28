<?php

namespace Base\Market\Entity\Product\Attribute\Adapter;

use Base\Market\Repository\Product\Attribute\Adapter\BarcodeAdapterRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Form\Extension\Core\Type\TextType;

#[ORM\Entity(repositoryClass: BarcodeAdapterRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'barcode')]
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

    #[ORM\Column(type: 'barcode')]
    protected $standard;

    /**
     * @return mixed
     */
    public function getStandard()
    {
        return $this->standard;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function setStandard($standard): self
    {
        $this->standard = $standard;

        return $this;
    }
}
