<?php

namespace Base\Marketplace\Invoice\Transmission\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Where the shop stands in what an approved platform has for it: the cursor
 * of the flows coming in (Omnibill\Request\Receive) - the buyers' statuses
 * about the shop's invoices (approved, refused, paid...), which a platform
 * gives as lifecycle flows, not on the invoice's own flow. One per gateway,
 * the shop's own: omnibase/ledger keeps its cursor apart, for the suppliers'
 * invoices, and each takes its part of the same flows.
 *
 * Mapped only when omnisense/omnibill is installed (MarketplaceExtension).
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_invoice_inbox')]
#[ORM\UniqueConstraint(name: 'marketplace_invoice_inbox_gateway', columns: ['gateway'])]
class InvoiceInbox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    protected string $gateway;

    #[ORM\Column(name: '`cursor`', type: 'string', length: 255, nullable: true)]
    protected ?string $cursor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $readAt = null;

    public function __construct(string $gateway)
    {
        $this->gateway = $gateway;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGateway(): string
    {
        return $this->gateway;
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    public function advance(?string $cursor): static
    {
        $this->cursor = $cursor;
        $this->readAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
