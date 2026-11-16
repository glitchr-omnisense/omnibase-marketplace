<?php

namespace Base\Marketplace\Repository\Sales\Referral;

use Base\Marketplace\Entity\Sales\Referral\ReferralCode;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<ReferralCode> */
class ReferralCodeRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, ReferralCode::class);
    }
}
