<?php

namespace Base\Marketplace\Model;

use Base\Marketplace\Entity\Product\Option;

/**
 * The options chosen for one line, checked against the product's groups
 * (Service\ProductOptions::select()): what they add to the unit price, how
 * they read, and what the line keeps of them - a copy, since the product's
 * options may change after the order.
 */
final class OptionSelection
{
    /** @param list<Option> $options in the order of their groups */
    public function __construct(public readonly array $options = [])
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->options;
    }

    /** What the options add to the unit price: smallest unit, before VAT. */
    public function surcharge(): int
    {
        return array_sum(array_map(fn (Option $o) => $o->getPrice(), $this->options));
    }

    /** @return list<string> "Bien cuit", "Œuf mollet" */
    public function labels(?string $locale = null): array
    {
        return array_map(fn (Option $o) => $o->getLabel($locale), $this->options);
    }

    /** Two lines of the same product are one line when their options are the same. */
    public function key(): string
    {
        $ids = array_map(fn (Option $o) => (int) $o->getId(), $this->options);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * What an OrderItem stores.
     *
     * @return list<array{group: ?int, option: ?int, group_label: string, label: string, price: int}>
     */
    public function toArray(?string $locale = null): array
    {
        return array_map(fn (Option $o) => [
            'group' => $o->getGroup()?->getId(),
            'option' => $o->getId(),
            'group_label' => (string) $o->getGroup()?->getLabel($locale),
            'label' => $o->getLabel($locale),
            'price' => $o->getPrice(),
        ], $this->options);
    }
}
