<?php

namespace Base\Marketplace\Repository\Wishlist;

use Base\Marketplace\Entity\Wishlist\PayoutAccount;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<PayoutAccount> */
class PayoutAccountRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, PayoutAccount::class);
    }
}
