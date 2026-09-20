<?php

namespace Base\Market\Shopify\Webhook;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Has this exact webhook been seen before?
 *
 * Shopify's signature covers the body and nothing else - no timestamp - so
 * there is no freshness window to enforce and a captured payload stays valid
 * for ever. X-Shopify-Triggered-At exists but is outside the HMAC, so it
 * proves nothing.
 *
 * What is left is the delivery id, which Shopify keeps stable across its own
 * retries. Remembering it for a day turns "Shopify retried because our 200
 * was slow" into a no-op, and blunts a replay.
 *
 * The pool is optional on purpose (the same nullOnInvalid arrangement
 * base-bundle-wikidoc uses for its index cache): without one this returns
 * "not seen" every time and the integration leans on the idempotency that is
 * there anyway - Checkout::confirm() refuses a second confirmation, the
 * synchroniser's fingerprint refuses a second write. Deduplication is an
 * optimisation here, not the safety property.
 */
class ReplayGuard
{
    private const TTL = 86400;
    private const PREFIX = 'market.shopify.webhook.';

    public function __construct(private readonly ?CacheItemPoolInterface $cache = null)
    {
    }

    public function isReplay(?string $webhookId): bool
    {
        if (null === $this->cache || null === $webhookId || '' === $webhookId) {
            return false;
        }

        try {
            $item = $this->cache->getItem(self::PREFIX . preg_replace('/[^A-Za-z0-9_.-]/', '_', $webhookId));
            if ($item->isHit()) {
                return true;
            }

            $item->set(true)->expiresAfter(self::TTL);
            $this->cache->save($item);
        } catch (\Throwable) {
            // A cache that is down must never stop a webhook being processed.
            return false;
        }

        return false;
    }
}
