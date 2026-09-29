<?php

namespace Base\Market\Service;

use Base\Market\Model\CompanyRecord;
use Omnistate\Exception\OmnistateException;
use Omnistate\Identifier\Siren;
use Omnistate\Identifier\Siret;
use Omnistate\Omnistate;
use Psr\Log\LoggerInterface;

/**
 * Who a French company is, from its SIREN (9 digits) or SIRET (14), through
 * Omnistate (glitchr/omnistate): the State's free register, API Recherche
 * d'entreprises (omnistate/annuaire-entreprises: no key; INSEE's Sirene and
 * the RNE - the same companies as Infogreffe's, without its fee). The number
 * is checked here first (its length, its Luhn key), then looked up;
 * Omnistate keeps the answer (omnistate.ttl, a day by default).
 */
class CompanyRegistry
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const INVALID = 'invalid';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly Omnistate $omnistate,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** The digits of a SIREN or SIRET as typed ("552 100 554 00054"). */
    public static function normalize(?string $number): string
    {
        return preg_replace('/\D+/', '', (string) $number);
    }

    /** A SIREN or a SIRET by its form: 9 or 14 digits, and its Luhn key (La Poste's SIRETs aside). */
    public static function isWellFormed(?string $number): bool
    {
        $digits = self::normalize($number);

        return Siren::isValid($digits) || Siret::isValid($digits);
    }

    /**
     * @return array{status: string, company: ?CompanyRecord} FOUND with the
     *         company, or NOT_FOUND, INVALID (not a SIREN/SIRET), UNAVAILABLE
     *         (the register did not answer: not the visitor's fault)
     */
    public function lookup(?string $number): array
    {
        $digits = self::normalize($number);
        if (!self::isWellFormed($digits)) {
            return ['status' => self::INVALID, 'company' => null];
        }

        try {
            $company = $this->omnistate->company($digits);
        } catch (OmnistateException $e) {
            $this->logger?->warning('The company register did not answer for {number}: {error}', ['number' => $digits, 'error' => $e->getMessage()]);

            return ['status' => self::UNAVAILABLE, 'company' => null];
        }

        // A SIRET: that establishment - it may be closed while the company trades on.
        $establishment = 14 === \strlen($digits) ? $company?->establishment($digits) : $company?->headOffice;
        if (null === $company || (14 === \strlen($digits) && null === $establishment)) {
            return ['status' => self::NOT_FOUND, 'company' => null];
        }

        return ['status' => self::FOUND, 'company' => new CompanyRecord(
            siren: $company->identifier,
            siret: $establishment?->identifier,
            name: $company->legalName ?? $company->name,
            address: null !== $establishment?->address ? (string) $establishment->address : null,
            activity: null !== $company->activity ? (string) $company->activity : null,
            legalForm: null !== $company->legalForm ? (string) $company->legalForm : null,
            active: $company->isActive() && (14 !== \strlen($digits) || $establishment->isActive()),
            createdOn: $company->createdOn?->format('Y-m-d'),
        )];
    }

    /**
     * One line for the back office, from a stored check: "✓ Name - address"
     * (verified, trading), "⚠ closed", "? not found", "… unchecked".
     *
     * @param array<string, mixed>|null $check
     */
    public static function companyBadge(?string $siret, ?array $check): ?string
    {
        if (!$siret) {
            return null;
        }

        return match ($check['status'] ?? null) {
            self::FOUND => (($check['active'] ?? false) ? '✓ ' : '⚠ FERMÉE · ').($check['name'] ?? '').(isset($check['address']) ? ' - '.$check['address'] : ''),
            self::NOT_FOUND => '? introuvable au registre ('.$siret.')',
            self::INVALID => '✗ numéro invalide ('.$siret.')',
            default => '… non vérifiée ('.$siret.')',
        };
    }
}
