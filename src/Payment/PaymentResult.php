<?php

namespace Base\Marketplace\Payment;

/** What a gateway's pay() came to - see PaymentGatewayInterface. */
final class PaymentResult
{
    public const PAID = 'paid';
    public const PENDING = 'pending';
    public const REDIRECT = 'redirect';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        /** A translation key in the "marketplace" domain, for the buyer. */
        public readonly ?string $reason = null,
        public readonly array $parameters = [],
    ) {
    }

    public static function paid(): self { return new self(self::PAID); }
    public static function pending(): self { return new self(self::PENDING); }
    public static function redirect(string $url): self { return new self(self::REDIRECT, $url); }
    public static function refused(string $reason, array $parameters = []): self { return new self(self::REFUSED, null, $reason, $parameters); }

    public function isPaid(): bool { return self::PAID === $this->status; }
    public function isPending(): bool { return self::PENDING === $this->status; }
    public function isRedirect(): bool { return self::REDIRECT === $this->status; }
    public function isRefused(): bool { return self::REFUSED === $this->status; }
}
