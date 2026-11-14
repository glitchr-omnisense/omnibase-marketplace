<?php

namespace Base\Marketplace\Entity\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Model\ConsentStatement;
use Base\Marketplace\Repository\Order\ConsentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A statement its buyer made before paying an order (Service\Consents, Model\ConsentStatement):
 * which one, the exact text shown and its SHA-256, the language it was shown in, the version of
 * the terms of sale in force (marketplace.consent.terms.version), where it was made (the shop's
 * checkout, a client's one-click purchase) and when.
 *
 * Nothing about the device it was made from: no IP address, no user agent - the shop keeps none
 * elsewhere either. The buyer is the order's customer. Gone with its order (only an abandoned
 * cart is ever deleted).
 */
#[ORM\Entity(repositoryClass: ConsentRepository::class)]
#[ORM\Table(name: 'marketplace_order_consent')]
#[ORM\Index(columns: ['statement'], name: 'marketplace_order_consent_statement')]
class Consent
{
    public const CHECKOUT = 'checkout';
    public const HUB = 'hub';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    /** ConsentStatement's id: terms, immediate_supply, early_service. */
    #[ORM\Column(length: 32)]
    private string $statement;

    /** The text as it was shown, word for word. */
    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(length: 16)]
    private string $locale;

    /** The page the statement refers to, as linked (the terms of sale). */
    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $url;

    /** The terms of sale's version as the host names it ("2026-11-14"), when it does. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $termsVersion;

    /** checkout, or hub (a one-click purchase of omnibase/forge's clients). */
    #[ORM\Column(length: 16)]
    private string $channel;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $acceptedAt;

    public function __construct(Order $order, ConsentStatement $statement, string $channel, ?string $termsVersion = null, ?\DateTimeImmutable $acceptedAt = null)
    {
        $this->order = $order;
        $this->statement = $statement->id;
        $this->text = $statement->text;
        $this->hash = $statement->hash;
        $this->locale = $statement->locale;
        $this->url = $statement->url;
        $this->termsVersion = $termsVersion;
        $this->channel = $channel;
        $this->acceptedAt = $acceptedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getStatement(): string { return $this->statement; }
    public function getText(): string { return $this->text; }
    public function getHash(): string { return $this->hash; }
    public function getLocale(): string { return $this->locale; }
    public function getUrl(): ?string { return $this->url; }
    public function getTermsVersion(): ?string { return $this->termsVersion; }
    public function getChannel(): string { return $this->channel; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
}
