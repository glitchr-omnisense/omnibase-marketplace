<?php

namespace Base\Marketplace\Shopify\Api;

/**
 * Shopify signs a webhook body with the app's API secret and sends the digest
 * in X-Shopify-Hmac-Sha256.
 *
 * Three differences from StripeGateway::verify(), all of them easy to get
 * wrong: the digest is base64 of the RAW BINARY hash, not hex; the header
 * holds one value, not "t=...,v1=..."; and there is NO timestamp in it. The
 * X-Shopify-Triggered-At header exists but is not covered by the signature,
 * so it cannot be trusted for freshness and there is no replay window to
 * enforce here. Replay defence is the webhook id (see ReplayGuard) plus the
 * fact that every action taken is idempotent.
 */
final class Hmac
{
    public static function verify(string $payload, ?string $header, string $secret): bool
    {
        if (null === $header || '' === $header || '' === $secret) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $payload, $secret, true)), $header);
    }

    /** The header Shopify would send for this body - used by the tests. */
    public static function sign(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }
}
