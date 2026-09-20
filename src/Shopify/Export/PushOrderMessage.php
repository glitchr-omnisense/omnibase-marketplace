<?php

namespace Base\Market\Shopify\Export;

/**
 * "Push order #id to Shopify."
 *
 * Carries the id and nothing else: by the time a worker picks this up the
 * entity it names may have moved on, and the worker should read the current
 * state rather than a snapshot taken at dispatch.
 */
final class PushOrderMessage
{
    public function __construct(public readonly int $orderId)
    {
    }
}
