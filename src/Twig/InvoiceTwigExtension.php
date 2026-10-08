<?php

namespace Base\Marketplace\Twig;

use Symfony\Component\Intl\Currencies;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * What an invoice's page prints its amounts with: an integer in the
 * currency's smallest unit, written as the seller's language writes money.
 *
 *   {{ invoice.total|invoice_money(invoice.currency) }}            1 234,56 €
 *   {{ line.rate|invoice_rate }}                                   20 % / 5,5 %
 */
final class InvoiceTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('invoice_money', $this->money(...)),
            new TwigFilter('invoice_rate', $this->rate(...)),
        ];
    }

    public function money(int|float|null $amount, string $currency, string $locale = 'fr_FR'): string
    {
        $value = ((float) $amount) / 10 ** Currencies::getFractionDigits($currency);
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($value, $currency);
    }

    public function rate(int|float|null $rate, string $locale = 'fr_FR'): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);

        return $formatter->format((float) $rate).' %';
    }
}
