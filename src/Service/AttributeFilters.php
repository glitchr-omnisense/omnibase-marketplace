<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\AttributeSet;
use Base\Marketplace\Entity\Product\AttributeSet\Field;
use Symfony\Component\HttpFoundation\Request;

/**
 * The list's filters, built from the attributes a set marks filterable:
 * for a choice, the values met among the products and how many have each
 * (a wine's colour, appellation, grapes); for a range, the lowest and the
 * highest (its alcohol, its keeping). The visitor's choice comes in the
 * query string - ?f[colour][]=rouge&f[alcohol][min]=12 - and narrows the
 * products, every filter at once, a value of a list among its items
 * ("Merlot" in "Merlot, Cabernet franc").
 *
 * In memory, over the products given: right for a shop of a few hundred
 * references; a large catalogue filters in Typesense instead (plan 0 §1.7).
 */
class AttributeFilters
{
    /** @return array<string, list<string>|array{min?: float, max?: float}> code => values, or a range */
    public function selection(Request $request): array
    {
        $selection = [];
        foreach ((array) ($request->query->all()['f'] ?? []) as $code => $value) {
            $code = (string) $code;
            if (\is_array($value) && (isset($value['min']) || isset($value['max']))) {
                $range = array_filter(['min' => $value['min'] ?? null, 'max' => $value['max'] ?? null], fn ($v) => null !== $v && '' !== $v && is_numeric($v));
                if ($range) {
                    $selection[$code] = array_map('floatval', $range);
                }
                continue;
            }
            $values = array_values(array_filter(array_map('strval', (array) $value), fn ($v) => '' !== trim($v)));
            if ($values) {
                $selection[$code] = $values;
            }
        }

        return $selection;
    }

    /**
     * @param iterable<Product> $products
     *
     * @return list<array{code: string, label: string, filter: string, unit: ?string, values: array<string, int>, min: ?float, max: ?float}>
     */
    public function facets(iterable $products, ?AttributeSet $set, ?string $locale = null): array
    {
        if (!$set) {
            return [];
        }
        $products = \is_array($products) ? $products : iterator_to_array($products, false);
        $facets = [];
        foreach ($set->getFilterableFields() as $field) {
            $code = (string) $field->getCode();
            $facet = ['code' => $code, 'label' => (string) $field, 'filter' => $field->getFilter(), 'unit' => $this->unitOf($field), 'values' => [], 'min' => null, 'max' => null];
            foreach ($products as $product) {
                foreach ($this->valuesOf($product, $code, $locale) as $value) {
                    if (Field::FILTER_RANGE === $field->getFilter()) {
                        if (is_numeric($value)) {
                            $facet['min'] = null === $facet['min'] ? (float) $value : min($facet['min'], (float) $value);
                            $facet['max'] = null === $facet['max'] ? (float) $value : max($facet['max'], (float) $value);
                        }
                    } else {
                        $facet['values'][$value] = ($facet['values'][$value] ?? 0) + 1;
                    }
                }
            }
            ksort($facet['values'], \SORT_NATURAL | \SORT_FLAG_CASE);
            if ($facet['values'] || null !== $facet['min']) {
                $facets[] = $facet;
            }
        }

        return $facets;
    }

    /**
     * The products every chosen filter holds.
     *
     * @param iterable<Product>                                             $products
     * @param array<string, list<string>|array{min?: float, max?: float}> $selection
     *
     * @return list<Product>
     */
    public function apply(iterable $products, array $selection, ?string $locale = null): array
    {
        $kept = [];
        foreach ($products as $product) {
            foreach ($selection as $code => $wanted) {
                $values = $this->valuesOf($product, (string) $code, $locale);
                if (isset($wanted['min']) || isset($wanted['max'])) {
                    $numbers = array_map('floatval', array_filter($values, 'is_numeric'));
                    $in = array_filter($numbers, fn ($n) => (!isset($wanted['min']) || $n >= $wanted['min']) && (!isset($wanted['max']) || $n <= $wanted['max']));
                    if (!$in) {
                        continue 2;
                    }
                } elseif (!array_intersect(array_map('mb_strtolower', $wanted), array_map('mb_strtolower', $values))) {
                    continue 2;
                }
            }
            $kept[] = $product;
        }

        return $kept;
    }

    /**
     * A product's values of an attribute, as strings: a list split on its
     * commas, a variant's own values too (a vintage's alcohol).
     *
     * @return list<string>
     */
    public function valuesOf(Product $product, string $code, ?string $locale = null): array
    {
        $values = [];
        $candidates = array_merge([$product], $product->getVariants()->filter(fn ($v) => $v instanceof Product)->toArray());
        foreach ($candidates as $candidate) {
            $value = $candidate->getAttributeValue($code, $locale);
            foreach (\is_array($value) ? $value : preg_split('/\s*[,;]\s*/', trim((string) $value)) as $item) {
                $item = trim((string) $item);
                if ('' !== $item && !\in_array($item, $values, true)) {
                    $values[] = $item;
                }
            }
        }

        return $values;
    }

    private function unitOf(Field $field): ?string
    {
        return $field->getUnit();
    }
}
