<?php

namespace Base\Market\Entity\Order\Transaction;

use Base\Market\Repository\Order\Transaction\TokenRepository;
use Base\Annotations\Annotation\Timestamp;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Payum\Core\Model\Token as BaseToken;
use Payum\Core\Security\TokenInterface;

/**
 * @ORM\Entity(repositoryClass=TokenRepository::class)
 */
class Token extends BaseToken implements TokenInterface, IconizeInterface
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
     * @return string
     */
    public function getId()
    {
        return $this->hash;
    }

    /**
     * @ORM\Column(type="datetime")
     *
     * @Timestamp(on="create")
     */
    protected $createdAt;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @ORM\Column(type="datetime")
     *
     * @Timestamp(on={"update", "create"})
     */
    protected $updatedAt;

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
