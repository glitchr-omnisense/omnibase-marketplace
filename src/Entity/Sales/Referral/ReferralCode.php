<?php

namespace Base\Marketplace\Entity\Sales\Referral;

use Base\Entity\User;
use Base\Marketplace\Repository\Sales\Referral\ReferralCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/** A member's referral code: one each, the part of the link they share (an application route: /p/{code}). */
#[ORM\Entity(repositoryClass: ReferralCodeRepository::class)]
#[ORM\Table(name: 'marketplace_referral_code')]
class ReferralCode implements \Stringable
{
    /** Crockford's base 32: nothing to misread when it is dictated. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 16, unique: true)]
    private string $code;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, ?string $code = null)
    {
        $this->owner = $owner;
        $this->code = $code ?? self::generate();
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function generate(int $length = 8): string
    {
        $code = '';
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    public static function normalize(string $code): string
    {
        return strtr(strtoupper(trim($code)), ['O' => '0', 'I' => '1', 'L' => '1', '-' => '', ' ' => '']);
    }

    public function __toString(): string { return $this->code; }
    public function getId(): ?int { return $this->id; }
    public function getOwner(): ?User { return $this->owner; }
    public function getCode(): string { return $this->code; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
