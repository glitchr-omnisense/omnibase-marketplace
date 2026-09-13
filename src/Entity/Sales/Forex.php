<?php

namespace Base\Market\Entity\Sales;

use Base\Market\Repository\Sales\ForexRepository;
use Base\Database\Attribute\Timestamp;
use Base\Database\Attribute\Cache;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ForexRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class Forex implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-balance-scale'];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    #[ORM\Column(type: 'string', length: 3)]
    protected $source;

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 3)]
    protected $target;

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function setTarget(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected $provider;

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    #[ORM\Column(type: 'float')]
    protected $rate;

    public function getRate(): ?float
    {
        return $this->rate;
    }

    public function setRate(float $rate, ?string $provider = null): self
    {
        $this->rate = $rate;
        $this->provider = null;

        return $this;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: ['create', 'update'])]
    protected $createdAt;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: ['create', 'update'])]
    protected $updatedAt;

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
