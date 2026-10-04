<?php

namespace Base\Marketplace\Supply;

/** A supplier refused, did not answer, or does not do what was asked. */
class SupplyException extends \RuntimeException
{
    public function __construct(public readonly string $supplier, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
