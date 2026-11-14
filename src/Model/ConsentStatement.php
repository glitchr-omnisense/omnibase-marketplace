<?php

namespace Base\Marketplace\Model;

/**
 * One statement a buyer must make before paying, as it is shown to them (Service\Consents):
 * its id, its text in the buyer's language - translated, as the box's label or the client's
 * line -, the page it refers to (the terms of sale), and the SHA-256 of that text, which is
 * what proves later which wording was accepted.
 *
 *   terms             the terms of sale read and accepted;
 *   immediate_supply  digital content: immediate supply asked for, and the loss of the right of
 *                     withdrawal acknowledged (Code de la consommation L221-28 13°);
 *   early_service     a service: performance asked for before the withdrawal period ends
 *                     (L221-25), and the loss of the right once fully performed acknowledged
 *                     (L221-28 1°).
 */
final class ConsentStatement
{
    public const TERMS = 'terms';
    public const IMMEDIATE_SUPPLY = 'immediate_supply';
    public const EARLY_SERVICE = 'early_service';

    public readonly string $hash;

    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly string $locale,
        public readonly ?string $url = null,
    ) {
        $this->hash = hash('sha256', $text);
    }

    /** @return array{id: string, text: string, hash: string, url?: string} as the API gives it */
    public function toArray(): array
    {
        return array_filter(['id' => $this->id, 'text' => $this->text, 'hash' => $this->hash, 'url' => $this->url], static fn ($value) => null !== $value);
    }
}
