<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file somebody gave the shop: with a quote request (a logo, a photo of
 * the shopfront, a plan), or for an order line (the artwork of a printed
 * item). Kept outside the public directory (marketplace.attachments.directory)
 * under a name nobody chose; read through the back office, or through a
 * signed link (Service\Attachments).
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'marketplace_attachment')]
class Attachment implements \Stringable
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    #[ORM\ManyToOne(targetEntity: OrderItem::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'order_item_id', nullable: true, onDelete: 'CASCADE')]
    private ?OrderItem $orderItem = null;

    /** Relative to the attachments' directory: "quote/2026/10/3f…a1.pdf". */
    #[ORM\Column(length: 255)]
    private string $path;

    /** The name it had on the sender's machine: shown, and given back on download. */
    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 120)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column]
    private int $size = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $path, string $name, string $mimeType = 'application/octet-stream', int $size = 0)
    {
        $this->path = $path;
        $this->name = mb_substr($name, 0, 255);
        $this->mimeType = mb_substr($mimeType, 0, 120);
        $this->size = $size;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getQuote(): ?Quote { return $this->quote; }
    public function setQuote(?Quote $quote): self { $this->quote = $quote; return $this; }
    public function getOrderItem(): ?OrderItem { return $this->orderItem; }
    public function setOrderItem(?OrderItem $orderItem): self { $this->orderItem = $orderItem; return $this; }
    public function getPath(): string { return $this->path; }
    public function getName(): string { return $this->name; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
