<?php

namespace Base\Marketplace\Enum;

/** What a list is for: gifts wished for (a wedding, a birthday), a birth list, or a fund alone (a honeymoon). */
enum WishlistKind: string
{
    case GIFTS = 'gifts';
    case BIRTH = 'birth';
    case FUND = 'fund';
}
