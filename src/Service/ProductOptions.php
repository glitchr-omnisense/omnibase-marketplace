<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Option;
use Base\Marketplace\Model\OptionSelection;

/**
 * Checks what a buyer chose among a product's options (Product\OptionGroup):
 * each option is one of the product's and can be had, a single-choice group
 * holds one, every group holds between its minimum and its maximum. A group
 * left untouched takes its preselected options.
 */
class ProductOptions
{
    /**
     * @param iterable<int|string|Option> $chosen option ids (or the options)
     *
     * @throws CartException options.error.unknown, .unavailable, .single, .minimum, .maximum
     */
    public function select(Product $product, iterable $chosen = []): OptionSelection
    {
        $groups = $product->getOptionGroups();
        $byId = [];
        foreach ($groups as $group) {
            foreach ($group->getOptions() as $option) {
                $byId[(int) $option->getId()] = $option;
            }
        }

        $picked = [];
        foreach ($chosen as $one) {
            $option = $one instanceof Option ? $one : ($byId[(int) $one] ?? null);
            if (!$option instanceof Option || !\in_array($option, $byId, true)) {
                throw new CartException('options.error.unknown', ['{product}' => (string) $product]);
            }
            if (!$option->isAvailable()) {
                throw new CartException('options.error.unavailable', ['{option}' => (string) $option]);
            }
            $picked[spl_object_id($option)] = $option;
        }

        $selection = [];
        foreach ($groups as $group) {
            $mine = array_values(array_filter($picked, fn (Option $o) => $o->getGroup() === $group));
            if (!$mine) {
                // Not touched: what the shop preselected (as many as the group takes).
                $mine = \array_slice(array_values(array_filter($group->getAvailableOptions(), fn (Option $o) => $o->isDefault())), 0, $group->getMaximum());
            }
            $parameters = ['{group}' => (string) $group, '{minimum}' => $group->getMinimum(), '{maximum}' => (int) $group->getMaximum()];
            if (!$group->isMultiple() && \count($mine) > 1) {
                throw new CartException('options.error.single', $parameters);
            }
            if (\count($mine) < $group->getMinimum()) {
                throw new CartException('options.error.minimum', $parameters);
            }
            if (null !== $group->getMaximum() && \count($mine) > $group->getMaximum()) {
                throw new CartException('options.error.maximum', $parameters);
            }
            usort($mine, fn (Option $a, Option $b) => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
            array_push($selection, ...$mine);
        }

        return new OptionSelection($selection);
    }
}
