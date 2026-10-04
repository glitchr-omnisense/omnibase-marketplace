<?php

namespace Base\Marketplace\Model;

/**
 * What a PLAN or a CREDIT_PACK product gives once paid, kept as JSON on the
 * product (Product::$plan), so plans are data: made and priced in the back
 * office, never in code.
 *
 *   billing   "one_time" (a pass: access for `months` months, null = for
 *             good) or "recurring" (a subscription renewed every `interval`:
 *             month or year);
 *   grants    the rights, by key - a limit (an integer: "guests": 150,
 *             "events.major": 1), a switch (true/false: "themes.premium"), a
 *             rate ("commission": 0.02) or any value an application reads;
 *   credits   units credited at each payment, by kind ("ai": 200);
 *   level     how plans compare: a higher level is an upgrade.
 *
 * A product sold by size (a pass for 30, 80, 150 guests) is a product with
 * variants: each variant has its price and its own terms.
 */
final readonly class PlanTerms
{
    public const ONE_TIME = 'one_time';
    public const RECURRING = 'recurring';

    /**
     * @param array<string, int|float|bool|string|null> $grants
     * @param array<string, int>                        $credits
     */
    public function __construct(
        public string $billing = self::ONE_TIME,
        public ?string $interval = null,
        public ?int $months = null,
        public array $grants = [],
        public array $credits = [],
        public int $level = 0,
    ) {
        if (!\in_array($billing, [self::ONE_TIME, self::RECURRING], true)) {
            throw new \InvalidArgumentException(sprintf('A plan is billed "one_time" or "recurring", not "%s".', $billing));
        }
        if (self::RECURRING === $billing && !\in_array($interval, ['day', 'week', 'month', 'year'], true)) {
            throw new \InvalidArgumentException('A recurring plan renews every day, week, month or year.');
        }
    }

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            (string) ($data['billing'] ?? self::ONE_TIME),
            isset($data['interval']) ? (string) $data['interval'] : null,
            isset($data['months']) ? (int) $data['months'] : null,
            (array) ($data['grants'] ?? []),
            array_map('intval', (array) ($data['credits'] ?? [])),
            (int) ($data['level'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'billing' => $this->billing,
            'interval' => $this->interval,
            'months' => $this->months,
            'grants' => $this->grants,
            'credits' => $this->credits,
            'level' => $this->level,
        ], static fn ($v) => null !== $v && [] !== $v);
    }

    public function isRecurring(): bool
    {
        return self::RECURRING === $this->billing;
    }

    /** When a pass bought at $from ends; null: never (or, for a subscription, when it stops being paid). */
    public function expiry(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        return $this->isRecurring() || null === $this->months ? null : $from->modify(sprintf('+%d months', $this->months));
    }

    public function grant(string $key, mixed $default = null): mixed
    {
        return $this->grants[$key] ?? $default;
    }
}
