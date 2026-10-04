<?php

namespace Base\Marketplace\Model;

/**
 * What a list belongs to besides its owner: an event, a household, a
 * project - anything of the application's. The list keeps its type and id
 * (not a relation: the marketplace knows nothing of the application), and
 * Repository\Wishlist\WishlistRepository::findForHolder() finds it again.
 */
interface WishlistHolderInterface
{
    /** A name of the application's for this kind of holder: "occasion". */
    public function getWishlistHolderType(): string;

    public function getWishlistHolderId(): int|string|null;
}
