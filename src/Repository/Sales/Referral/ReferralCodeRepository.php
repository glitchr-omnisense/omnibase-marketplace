<?php

namespace Base\Marketplace\Repository\Sales\Referral;

use Base\Marketplace\Entity\Sales\Referral\ReferralCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ReferralCode> */
class ReferralCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferralCode::class);
    }
}
