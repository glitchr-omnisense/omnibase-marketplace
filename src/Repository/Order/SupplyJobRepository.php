<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order\SupplyJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SupplyJob> */
class SupplyJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupplyJob::class);
    }
}
