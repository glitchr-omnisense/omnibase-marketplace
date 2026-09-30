<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Model\VatCustomerInterface;
use Omnistate\Exception\OmnistateException;
use Omnistate\Identifier\VatNumber;
use Omnistate\Model\VatCheck;
use Omnistate\Omnistate;

/**
 * Intra-EU VAT numbers - a customer's, a store's - checked with the European
 * Commission's VIES through Omnistate (glitchr/omnistate, omnistate/vies)
 * when they are given, never while a page is built. A SIREN or a SIRET is
 * enough for a French business: its VAT number is its SIREN with a key.
 *
 * Asked with the shop's own VAT number (omnistate.requester), VIES answers a
 * consultation number: the proof a reverse-charge invoice was checked.
 * Nothing is flushed here.
 */
class VatNumbers
{
    public const CONFIRMED = 'confirmed';
    public const REMOVED = 'removed';
    public const NOT_A_NUMBER = 'not_a_number';
    public const UNKNOWN = 'unknown';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(private readonly Omnistate $omnistate)
    {
    }

    /**
     * What VIES says of a number as typed.
     *
     * @return array{status: string, number: ?string, check: ?VatCheck} CONFIRMED,
     *         NOT_A_NUMBER (no member state writes it that way: VIES is not
     *         asked), UNKNOWN, or UNAVAILABLE (VIES, or the member state's
     *         register, did not answer: not the number's fault); $number as
     *         VIES writes it ("DE123456789"), null when it is not one
     */
    public function check(string $typed): array
    {
        $number = VatNumber::normalize($typed);
        if (null === $number || !VatNumber::isWellFormed($number)) {
            return ['status' => self::NOT_A_NUMBER, 'number' => null, 'check' => null];
        }

        try {
            $check = $this->omnistate->vat($number);
        } catch (OmnistateException) {
            return ['status' => self::UNAVAILABLE, 'number' => $number, 'check' => null];
        }

        return ['status' => $check->valid ? self::CONFIRMED : self::UNKNOWN, 'number' => $number, 'check' => $check];
    }

    /**
     * A customer's number: set when VIES knows it, taken away when $typed is
     * empty, left as it was otherwise.
     *
     * @return array{status: string, number: ?string, check: ?VatCheck} as check(), or REMOVED
     */
    public function confirm(VatCustomerInterface $customer, ?string $typed): array
    {
        if ('' === trim((string) $typed)) {
            $customer->setVatNumber(null);

            return ['status' => self::REMOVED, 'number' => null, 'check' => null];
        }

        $result = $this->check($typed);
        if (self::CONFIRMED === $result['status']) {
            $customer->setVatNumber($result['number'])->markVatNumberChecked($result['check']->checkedAt, $result['check']->consultationNumber);
        }

        return $result;
    }
}
