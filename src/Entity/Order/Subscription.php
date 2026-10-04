<?php

namespace Base\Marketplace\Entity\Order;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\SubscriptionStatus;
use Base\Marketplace\Repository\Order\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A plan paid by subscription: who subscribes, to which plan, through which
 * omnitrade gateway, and the provider's own ids (the subscription, the
 * customer its portal opens for). Its state and the end of the period paid
 * follow the provider's events (Service\Subscriptions); the Entitlements it
 * carries last as long as it runs.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'marketplace_subscription')]
#[ORM\UniqueConstraint(name: 'marketplace_subscription_provider', columns: ['gateway', 'provider_reference'])]
class Subscription implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $subscriber = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    /** The order that started it. */
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    /** The omnitrade gateway's name (a PaymentMethod's gatewayFactory). */
    #[ORM\Column(length: 64)]
    private string $gateway = '';

    #[ORM\Column(name: 'provider_reference', length: 128)]
    private string $providerReference = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $providerCustomer = null;

    #[ORM\Column(length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status = SubscriptionStatus::INCOMPLETE;

    #[ORM\Column(length: 8, name: 'billing_interval')]
    private string $interval = 'month';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(User $subscriber, string $gateway, string $providerReference, ?Product $product = null, ?Order $order = null)
    {
        $this->subscriber = $subscriber;
        $this->gateway = $gateway;
        $this->providerReference = $providerReference;
        $this->product = $product;
        $this->order = $order;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->providerReference; }
    public function getId(): ?int { return $this->id; }
    public function getSubscriber(): ?User { return $this->subscriber; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): self { $this->product = $product; return $this; }
    public function getOrder(): ?Order { return $this->order; }
    public function getGateway(): string { return $this->gateway; }
    public function getProviderReference(): string { return $this->providerReference; }
    public function getProviderCustomer(): ?string { return $this->providerCustomer; }
    public function setProviderCustomer(?string $providerCustomer): self { $this->providerCustomer = $providerCustomer; return $this; }
    public function getStatus(): SubscriptionStatus { return $this->status; }

    public function setStatus(SubscriptionStatus $status): self
    {
        $this->status = $status;
        if (SubscriptionStatus::CANCELLED === $status) {
            $this->endedAt ??= new \DateTimeImmutable();
        }

        return $this;
    }

    public function getInterval(): string { return $this->interval; }
    public function setInterval(string $interval): self { $this->interval = $interval; return $this; }
    public function getCurrentPeriodEnd(): ?\DateTimeImmutable { return $this->currentPeriodEnd; }
    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): self { $this->currentPeriodEnd = $currentPeriodEnd; return $this; }
    public function isCancelAtPeriodEnd(): bool { return $this->cancelAtPeriodEnd; }
    public function setCancelAtPeriodEnd(bool $cancelAtPeriodEnd): self { $this->cancelAtPeriodEnd = $cancelAtPeriodEnd; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }

    /** What it gives is due now. */
    public function isRunning(): bool { return $this->status->isRunning(); }
}
