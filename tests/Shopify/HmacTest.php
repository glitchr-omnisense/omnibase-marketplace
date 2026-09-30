<?php

namespace Tests\Base\Marketplace\Shopify;

use Base\Marketplace\Shopify\Api\Hmac;
use PHPUnit\Framework\TestCase;

/**
 * Shopify's webhook signature. Different from Stripe's in three ways that are
 * each easy to get wrong, so each gets a test.
 */
final class HmacTest extends TestCase
{
    private const SECRET = 'shpss_a4f1c0de0000000000000000000000';
    private const BODY = '{"id":5123456789,"financial_status":"paid"}';

    public function testAValidSignaturePasses(): void
    {
        self::assertTrue(Hmac::verify(self::BODY, Hmac::sign(self::BODY, self::SECRET), self::SECRET));
    }

    /** base64 of the raw digest, not hex - the usual first mistake. */
    public function testTheDigestIsBase64OfTheRawHashNotHex(): void
    {
        $header = Hmac::sign(self::BODY, self::SECRET);

        self::assertSame(base64_encode(hash_hmac('sha256', self::BODY, self::SECRET, true)), $header);
        self::assertFalse(Hmac::verify(self::BODY, hash_hmac('sha256', self::BODY, self::SECRET), self::SECRET));
    }

    public function testOneTamperedByteFails(): void
    {
        $header = Hmac::sign(self::BODY, self::SECRET);
        $tampered = str_replace('"paid"', '"paiD"', self::BODY);

        self::assertNotSame(self::BODY, $tampered);
        self::assertFalse(Hmac::verify($tampered, $header, self::SECRET));
    }

    public function testTheWrongSecretFails(): void
    {
        self::assertFalse(Hmac::verify(self::BODY, Hmac::sign(self::BODY, 'another-secret'), self::SECRET));
    }

    public function testAMissingHeaderFails(): void
    {
        self::assertFalse(Hmac::verify(self::BODY, null, self::SECRET));
        self::assertFalse(Hmac::verify(self::BODY, '', self::SECRET));
    }

    /**
     * An unconfigured secret must refuse everything. The opposite - treating
     * "no secret" as "no checking" - would leave the endpoint wide open on any
     * host that had not finished setting up.
     */
    public function testAnEmptySecretRefusesEverything(): void
    {
        self::assertFalse(Hmac::verify(self::BODY, Hmac::sign(self::BODY, ''), ''));
    }
}
