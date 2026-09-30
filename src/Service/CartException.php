<?php

namespace Base\Marketplace\Service;

/** Something the buyer should read; the message is a key in the "marketplace" domain. */
class CartException extends \RuntimeException
{
    public function __construct(string $key, private readonly array $parameters = [])
    {
        parent::__construct($key);
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}
