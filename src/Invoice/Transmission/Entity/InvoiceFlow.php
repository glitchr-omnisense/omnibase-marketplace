<?php

namespace Base\Marketplace\Invoice\Transmission\Entity;

use Base\Marketplace\Entity\Invoice;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where an invoice stands on the glitchr/omnibill gateway it went through:
 * the gateway, its reference of the transmission, the lifecycle statuses
 * known (sent, deposited, refused, paid...). One per invoice, beside it -
 * the invoice itself does not change once issued.
 *
 * Mapped only when glitchr/omnibill is installed (MarketplaceExtension):
 * a site without the family has no table for it, and needs none.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_invoice_flow')]
#[ORM\UniqueConstraint(name: 'marketplace_invoice_flow_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'marketplace_invoice_flow_reference', columns: ['reference'])]
class InvoiceFlow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: false, onDelete: 'CASCADE')]
    protected Invoice $invoice;

    /** The gateway's name in omnibill.gateways ("email", "afnor"...). */
    #[ORM\Column(type: 'string', length: 32)]
    protected string $gateway;

    /** The gateway's reference of the transmission: a flow id, a deposit number, a message id. */
    #[ORM\Column(type: 'string', length: 255)]
    protected string $reference;

    /** The latest lifecycle status (Omnibill\Model\LifecycleStatus's value). */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    protected ?string $status = null;

    /** @var list<array{status: string, code: int|null, at: string|null, reason: string|null}> oldest first */
    #[ORM\Column(type: 'json')]
    protected array $history = [];

    #[ORM\Column(type: 'datetime_immutable')]
    protected \DateTimeImmutable $updatedAt;

    public function __construct(Invoice $invoice, string $gateway, string $reference)
    {
        $this->invoice = $invoice;
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    /** Sent again, through a gateway: a new transmission, its history afresh. */
    public function restart(string $gateway, string $reference): static
    {
        $this->gateway = $gateway;
        $this->reference = $reference;
        $this->status = null;
        $this->history = [];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    /** @return list<array{status: string, code: int|null, at: string|null, reason: string|null}> */
    public function getHistory(): array
    {
        return $this->history;
    }

    /** @param list<array{status: string, code: int|null, at: string|null, reason: string|null}> $history */
    public function setHistory(array $history): static
    {
        $this->history = array_values($history);
        $this->status = [] === $this->history ? null : $this->history[array_key_last($this->history)]['status'];
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
