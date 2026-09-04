<?php

namespace Base\Market\Entity\Order\Transaction;

use Base\Market\Repository\Order\Transaction\DetailsRepository;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Payum\Core\Model\ArrayObject;

/**
 * @ORM\Entity(repositoryClass=DetailsRepository::class)
 */
class Details extends ArrayObject implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-coin'];
    }

    /**
     * @ORM\Id
     *
     * @ORM\GeneratedValue(strategy="IDENTITY")
     *
     * @ORM\Column(type="integer")
     */
    protected $id;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }
}
