<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The hand-overs: an order's, a tracking page's (by its token), a day's.
 *
 * @extends ServiceEntityRepository<Pickup>
 */
class PickupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pickup::class);
    }

    public function of(Order $order): ?Pickup
    {
        return null === $order->getId() ? null : $this->findOneBy(['order' => $order]);
    }

    /** @return list<Pickup> the orders made together, oldest first */
    public function byToken(string $token): array
    {
        return $this->findBy(['token' => $token], ['id' => 'ASC']);
    }

    /**
     * What the shop has to hand over on a day, a slot after the other; the
     * carts whose payment never came are left out.
     *
     * @return list<Pickup>
     */
    public function onDay(\DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.day = :day')->setParameter('day', $day->setTime(0, 0), 'date_immutable')
            ->andWhere('p.status != :checkout')->setParameter('checkout', PickupStatus::CHECKOUT)
            ->orderBy('p.slot', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }
}
