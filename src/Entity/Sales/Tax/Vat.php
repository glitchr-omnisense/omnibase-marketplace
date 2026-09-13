<?php

namespace Base\Market\Entity\Sales\Tax;

use Base\Market\Entity\Sales\Tax;
use Base\Market\Repository\Sales\Tax\VatRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VatRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Vat extends Tax implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-city'];
    }
}
