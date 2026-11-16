<?php

namespace Base\Marketplace\Repository\Sales\Referral;

use Base\Marketplace\Entity\Sales\Referral\Referral;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Referral> */
class ReferralRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Referral::class);
    }
}
