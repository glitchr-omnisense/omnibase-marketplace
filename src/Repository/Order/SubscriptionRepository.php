<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order\Subscription;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Subscription::class);
    }
}
