<?php

namespace Base\Marketplace\Service;

/** Not enough credit of that kind for what is asked. */
class CreditException extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly int $asked, public readonly int $balance)
    {
        parent::__construct(sprintf('%d credit(s) "%s" asked, %d left.', $asked, $kind, $balance));
    }
}
