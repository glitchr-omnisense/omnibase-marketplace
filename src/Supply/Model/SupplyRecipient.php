<?php

namespace Base\Marketplace\Supply\Model;

/** Who receives what is made: one address per supply order (a batch for the buyer, or each guest's own). */
final readonly class SupplyRecipient
{
    /** @param list<string> $street */
    public function __construct(
        public string $name,
        public array $street,
        public string $postcode,
        public string $city,
        public string $country = 'FR',
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $company = null,
        public ?string $state = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            array_values(array_filter(array_map('strval', (array) ($data['street'] ?? [])))),
            (string) ($data['postcode'] ?? ''),
            (string) ($data['city'] ?? ''),
            strtoupper((string) ($data['country'] ?? 'FR')),
            isset($data['email']) ? (string) $data['email'] : null,
            isset($data['phone']) ? (string) $data['phone'] : null,
            isset($data['company']) ? (string) $data['company'] : null,
            isset($data['state']) ? (string) $data['state'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn ($v) => null !== $v && [] !== $v);
    }

    /** "Marie" of "Marie Dupont de la Tour". */
    public function firstName(): string
    {
        return explode(' ', trim($this->name), 2)[0];
    }

    public function lastName(): string
    {
        return explode(' ', trim($this->name), 2)[1] ?? '';
    }

    public function isComplete(): bool
    {
        return '' !== $this->name && [] !== $this->street && '' !== $this->postcode && '' !== $this->city && 2 === \strlen($this->country);
    }

    /** What tells two recipients apart: lines for the same one travel together. */
    public function key(): string
    {
        return md5(json_encode([$this->name, $this->street, $this->postcode, $this->city, $this->country]));
    }
}
