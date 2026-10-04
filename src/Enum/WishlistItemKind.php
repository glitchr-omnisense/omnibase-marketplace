<?php

namespace Base\Marketplace\Enum;

/**
 * How a wish is fulfilled:
 *
 *   OBJECT  somebody reserves it and buys it themselves, at the shop it comes
 *           from (or in this shop when it is one of its products);
 *   SHARE   an object too dear for one: several contribute money towards its
 *           price, until it is reached;
 *   FUND    a pot with no ceiling (or a goal): each gives what they want.
 */
enum WishlistItemKind: string
{
    case OBJECT = 'object';
    case SHARE = 'share';
    case FUND = 'fund';

    public function takesMoney(): bool
    {
        return self::OBJECT !== $this;
    }
}
