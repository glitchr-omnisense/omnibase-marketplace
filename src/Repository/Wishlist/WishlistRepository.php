<?php

namespace Base\Marketplace\Repository\Wishlist;

use Base\Marketplace\Entity\Wishlist\Wishlist;
use Base\Marketplace\Model\WishlistHolderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Wishlist> */
class WishlistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wishlist::class);
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
