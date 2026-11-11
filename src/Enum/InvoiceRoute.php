<?php

namespace Base\Marketplace\Enum;

/**
 * Which of the French reform's channels an invoice takes, told from its
 * seller and buyer as they were frozen on it the day it was issued:
 *
 *   - DOMESTIC_B2B: a buyer that is a company established in France (its
 *     SIREN, its SIRET, a company name or a VAT number, with a French
 *     address or none). The invoice is an e-invoice: deposited on an
 *     approved platform (CGI art. 289 bis), addressed by the buyer's SIREN.
 *   - INTERNATIONAL_B2B: a company established abroad (a VAT number or a
 *     company name, an address outside France). No e-invoice: the
 *     transaction is e-reported, invoice by invoice (CGI art. 290).
 *   - B2C: anyone else, a private person. No e-invoice: the day's sales
 *     are e-reported, aggregated (CGI art. 290).
 *
 * A company "is known" by the identifiers the customer carries
 * (getSiren(), getSiret(), getCompanyName(), VatCustomerInterface): a
 * SIREN is checked against the company register (omnistate's
 * annuaire-entreprises) when the invoice goes to the platform, a VAT number
 * against VIES when the customer types it. A buyer called a company but
 * without a SIREN in France is still DOMESTIC_B2B: the platform cannot
 * address it, and the invoice is refused until it is corrected.
 *
 * A seller not established in France is outside the French reform: none()
 * says so, and nothing is routed.
 */
enum InvoiceRoute: string
{
    case DOMESTIC_B2B = 'b2b';
    case INTERNATIONAL_B2B = 'b2b_int';
    case B2C = 'b2c';

    /**
     * @param array<string, mixed> $seller the invoice's seller
     * @param array<string, mixed> $buyer  the invoice's buyer
     *
     * @return self|null null: a seller outside France, for which the French reform does not apply
     */
    public static function of(array $seller, array $buyer): ?self
    {
        if ('FR' !== strtoupper((string) (($seller['country'] ?? '') ?: 'FR'))) {
            return null;
        }
        $business = (bool) ($buyer['business'] ?? false)
            || !empty($buyer['siren']) || !empty($buyer['siret']) || !empty($buyer['vat_number']);
        if (!$business) {
            return self::B2C;
        }
        $country = strtoupper((string) ($buyer['address']['country'] ?? ''));
        // A French VAT number (FR + key + SIREN) says France too, when the address says nothing.
        if ('' === $country && !empty($buyer['vat_number'])) {
            $country = strtoupper(substr((string) $buyer['vat_number'], 0, 2));
        }

        return '' === $country || 'FR' === $country || !empty($buyer['siren']) || !empty($buyer['siret']) ? self::DOMESTIC_B2B : self::INTERNATIONAL_B2B;
    }

    /** The platform's processing rule (XP Z12-013's processingRule): B2B, B2BInt, B2C. */
    public function rule(): string
    {
        return match ($this) {
            self::DOMESTIC_B2B => 'B2B',
            self::INTERNATIONAL_B2B => 'B2BInt',
            self::B2C => 'B2C',
        };
    }

    /** Whether the invoice itself goes through the approved platform (an e-invoice), rather than being e-reported. */
    public function isEInvoice(): bool
    {
        return self::DOMESTIC_B2B === $this;
    }
}
