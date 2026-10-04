<?php

namespace Base\Marketplace\Wishlist;

/** Something a giver or an owner asked cannot be done; the message is a key of the marketplace translations (wishlist.error.*). */
class WishlistException extends \RuntimeException
{
    /** @param array<string, scalar> $parameters */
    public function __construct(string $key, public readonly array $parameters = [])
    {
        parent::__construct($key);
    }
}
