<?php

namespace Base\Market\Entity\Sales\Discount;

use Base\Market\Entity\Sales\Discount;
use Base\Entity\User;
use Base\Market\Repository\Sales\Discount\CouponRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\Length;

#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry('marketplace_sales_coupon')]
class Coupon extends Discount implements IconizeInterface
{
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getCode() ?? '';
    }

    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-tags'];
    }

    #[ORM\Column(type: 'string', length: 16, unique: true)]
    #[Length(min: 1, max: 16, groups: ['new', 'edit'])]
    protected $code;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper($code);

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $quota;

    public function getQuota(): ?int
    {
        return $this->quota;
    }

    public function setQuota(?int $quota): self
    {
        $this->quota = $quota;

        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    protected $quotaPerCustomer;

    public function getQuotaPerCustomer(): ?int
    {
        return $this->quotaPerCustomer;
    }

    public function setQuotaPerCustomer(?int $quotaPerCustomer): self
    {
        $this->quotaPerCustomer = $quotaPerCustomer;

        return $this;
    }

    #[ORM\Column(type: 'boolean')]
    protected $individualUse = false;

    public function isIndividualUse(): ?bool
    {
        return $this->individualUse;
    }

    public function setIndividualUse(bool $individualUse): self
    {
        $this->individualUse = $individualUse;

        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'coupons')]
    protected $owner;

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }
}
