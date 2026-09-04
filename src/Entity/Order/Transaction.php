<?php

namespace Base\Market\Entity\Order;

use Base\Market\Entity\Order;
use Base\Market\Enum\PaymentState;
use Base\Market\Repository\Order\TransactionRepository;
use Base\Annotations\Annotation\Timestamp;
use Base\Database\Annotation\Cache;
use Base\Service\Model\IconizeInterface;
use Doctrine\ORM\Mapping as ORM;
use Payum\Core\Model\DirectDebitPaymentInterface;
use Payum\Core\Model\Payment as BasePayment;
use Payum\Core\Model\PaymentInterface;

/**
 * @ORM\Entity(repositoryClass=TransactionRepository::class)
 *
 * @Cache(usage="NONSTRICT_READ_WRITE", associations="ALL")
 */
class Transaction extends BasePayment implements PaymentInterface, DirectDebitPaymentInterface, IconizeInterface
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
        parent::__construct();
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

    /**
     * @ORM\Column(type="payment_state")
     */
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

    /**
     * @ORM\Column(type="text", nullable=true)
     */
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

    /**
     * @ORM\Column(type="text", nullable=true)
     */
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

    /**
     * @ORM\ManyToOne(targetEntity=Order::class, inversedBy="transactions")
     */
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
