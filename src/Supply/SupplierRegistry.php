<?php

namespace Base\Marketplace\Supply;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The suppliers, by name: the bundle's (gelato, offline) and the application's. */
class SupplierRegistry
{
    /** @var array<string, SupplierInterface> */
    private array $suppliers = [];

    /** @param iterable<SupplierInterface> $suppliers */
    public function __construct(#[AutowireIterator('marketplace.supplier')] iterable $suppliers)
    {
        foreach ($suppliers as $supplier) {
            $this->suppliers[$supplier::name()] = $supplier;
        }
    }

    public function get(?string $name): ?SupplierInterface
    {
        return $this->suppliers[(string) $name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->suppliers);
    }

    /** @return array<string, SupplierInterface> those that can run */
    public function configured(): array
    {
        return array_filter($this->suppliers, static fn (SupplierInterface $s) => $s->isConfigured());
    }
}
