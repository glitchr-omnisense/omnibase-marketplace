<?php

namespace Base\Marketplace\Repository\Wishlist;

use Base\Marketplace\Entity\Wishlist\Wishlist;
use Base\Marketplace\Model\WishlistHolderInterface;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Wishlist> */
class WishlistRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Wishlist::class);
    }

    /** @return list<Wishlist> the lists of an event, a project... */
    public function findForHolder(WishlistHolderInterface $holder): array
    {
        return null === $holder->getWishlistHolderId() ? [] : $this->findBy(['holderType' => $holder->getWishlistHolderType(), 'holderId' => (string) $holder->getWishlistHolderId()], ['id' => 'ASC']);
    }

    public function findOneByToken(string $token): ?Wishlist
    {
        return $this->findOneBy(['token' => $token]);
    }
}
