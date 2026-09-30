<?php

namespace Base\Marketplace\Model;

/**
 * A French company as the State's register knows it (API Recherche
 * d'entreprises, fed by INSEE's Sirene and the RNE): kept with a quote or a
 * request, as it was when checked.
 */
final class CompanyRecord
{
    public function __construct(
        public readonly string $siren,
        public readonly ?string $siret,
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $activity,
        public readonly ?string $legalForm,
        /** Still trading: false when the company, or that establishment, is closed. */
        public readonly bool $active,
        public readonly ?string $createdOn,
        public readonly \DateTimeImmutable $checkedAt = new \DateTimeImmutable(),
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'siren' => $this->siren,
            'siret' => $this->siret,
            'name' => $this->name,
            'address' => $this->address,
            'activity' => $this->activity,
            'legal_form' => $this->legalForm,
            'active' => $this->active,
            'created_on' => $this->createdOn,
            'checked_at' => $this->checkedAt->format(\DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['siren'],
            $data['siret'] ?? null,
            (string) $data['name'],
            $data['address'] ?? null,
            $data['activity'] ?? null,
            $data['legal_form'] ?? null,
            (bool) ($data['active'] ?? false),
            $data['created_on'] ?? null,
            new \DateTimeImmutable($data['checked_at'] ?? 'now'),
        );
    }
}
