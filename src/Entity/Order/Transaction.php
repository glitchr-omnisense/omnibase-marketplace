<?php

namespace Base\Market\Entity\Order;

use Base\Market\Entity\Order;
use Base\Market\Enum\PaymentState;
use Base\Market\Repository\Order\TransactionRepository;
use Base\Database\Attribute\Timestamp;
use Base\Database\Attribute\Cache;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
class Transaction implements IconizeInterface
{
    public function __iconize(): ?array
    {
        return null;
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-cash-register'];
    }

    public function __construct()
    {
        $this->details = [];
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /*
     * What Payum's Payment model used to carry, kept as plain columns: the
     * gateways (see Base\Market\Payment\PaymentGatewayInterface) read and
     * write them, and nothing ties the bundle to Payum any more.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $number = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    protected ?string $clientEmail = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $clientId = null;

    /** In the smallest unit of the currency (cents, or one token). */
    #[ORM\Column(type: 'integer')]
    protected int $totalAmount = 0;

    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    protected ?string $currencyCode = null;

    /** Whatever the gateway needs to remember about this payment. */
    #[ORM\Column(type: 'json')]
    protected array $details = [];

    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): self { $this->number = $number; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getClientEmail(): ?string { return $this->clientEmail; }
    public function setClientEmail(?string $clientEmail): self { $this->clientEmail = $clientEmail; return $this; }
    public function getClientId(): ?string { return $this->clientId; }
    public function setClientId(?string $clientId): self { $this->clientId = $clientId; return $this; }
    public function getTotalAmount(): int { return $this->totalAmount; }
    public function setTotalAmount(int $totalAmount): self { $this->totalAmount = $totalAmount; return $this; }
    public function getCurrencyCode(): ?string { return $this->currencyCode; }
    public function setCurrencyCode(?string $currencyCode): self { $this->currencyCode = $currencyCode; return $this; }
    public function getDetails(): array { return $this->details; }
    public function setDetails(array $details): self { $this->details = $details; return $this; }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    #[ORM\Column(type: 'payment_state')]
    protected $state = PaymentState::AWAITING;

    /**
     * @return bool
     */
    public function isWaiting()
    {
        return PaymentState::AWAITING == $this->state;
    }

    /**
     * @return bool
     */
    public function isPaid()
    {
        return PaymentState::PAID == $this->state;
    }

    /**
     * @return bool
     */
    public function isRefunded()
    {
        return PaymentState::REFUND == $this->state;
    }

    /**
     * @return bool
     */
    public function isCancelled()
    {
        return PaymentState::CANCEL == $this->state;
    }

    /**
     * @return mixed|string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param $state
     * @return $this
     */
    /**
     * @param $state
     * @return $this
     */
    public function setState($state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function markAsPaid()
    {
        $this->state = PaymentState::PAID;

        return $this;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function markAsCancelled()
    {
        $this->state = PaymentState::CANCEL;

        return $this;
    }

    /**
     * @return $this
     */
    /**
     * @return $this
     */
    public function markAsRefunded()
    {
        $this->state = PaymentState::REFUND;

        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    protected $comment;

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    protected $webhook;

    public function getWebhook(): ?string
    {
        return $this->webhook;
    }

    public function setWebhook(?string $webhook): self
    {
        $this->webhook = $webhook;

        return $this;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: 'create')]
    protected $createdAt;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    #[ORM\Column(type: 'datetime')]
    #[\Base\Database\Attribute\Timestamp(on: ['update', 'create'])]
    protected $updatedAt;

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'transactions')]
    protected $order;

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }
}
