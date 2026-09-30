<?php

namespace Base\Marketplace\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * VatCustomerInterface on an app's User entity: the number, when VIES
 * confirmed it and its consultation number.
 */
trait VatCustomerTrait
{
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    protected ?string $vatNumber = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $vatNumberCheckedAt = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $vatConsultationNumber = null;

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        if ($vatNumber !== $this->vatNumber) {
            $this->vatNumberCheckedAt = null;
            $this->vatConsultationNumber = null;
        }
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getVatNumberCheckedAt(): ?\DateTimeImmutable
    {
        return $this->vatNumberCheckedAt;
    }

    public function getVatConsultationNumber(): ?string
    {
        return $this->vatConsultationNumber;
    }

    public function markVatNumberChecked(?\DateTimeImmutable $at = null, ?string $consultationNumber = null): static
    {
        $this->vatNumberCheckedAt = $at ?? new \DateTimeImmutable();
        $this->vatConsultationNumber = $consultationNumber;

        return $this;
    }

    public function getVatCountry(): ?string
    {
        return $this->vatNumber ? substr($this->vatNumber, 0, 2) : null;
    }

    public function hasConfirmedVatNumber(): bool
    {
        return null !== $this->vatNumber && null !== $this->vatNumberCheckedAt;
    }
}
