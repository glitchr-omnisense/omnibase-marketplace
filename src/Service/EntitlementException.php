<?php

namespace Base\Marketplace\Service;

/** What was asked is not granted, or none is left: the application offers an upgrade. */
class EntitlementException extends \RuntimeException
{
    public function __construct(public readonly string $key, string $message = '')
    {
        parent::__construct($message ?: sprintf('Nothing grants "%s", or none is left.', $key));
    }
}
