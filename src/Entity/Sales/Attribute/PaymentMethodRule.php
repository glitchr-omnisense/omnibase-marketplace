<?php

namespace Base\Marketplace\Entity\Sales\Attribute;

use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Repository\Sales\Attribute\PaymentMethodRuleRepository;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Layout\Attribute\Common\AbstractRule;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentMethodRuleRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry(value: 'rule_paymentMethod')]
class PaymentMethodRule extends AbstractRule
{
    public function get(?string $locale = null): mixed
    {
        return $this->getValue();
    }

    public function set(...$args): self
    {
        return $this;
    }

    public function resolve(?string $locale = null): mixed
    {
        return $this->adapter ? $this->adapter->resolve($this->get($locale)) : null;
    }

    #[ORM\JoinColumn(nullable: false)]
    #[ORM\ManyToOne(targetEntity: PaymentMethod::class, inversedBy: 'rules')]
    protected $paymentMethod;

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethod $paymentMethod): self
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }
}
