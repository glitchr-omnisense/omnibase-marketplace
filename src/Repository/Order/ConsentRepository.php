<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Consent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Consent> */
class ConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Consent::class);
    }

    /** @return list<Consent> the statements made for the order, in the order they were shown */
    public function ofOrder(Order $order): array
    {
        if (null === $order->getId()) {
            return [];
        }

        return $this->findBy(['order' => $order], ['id' => 'ASC']);
    }
}
