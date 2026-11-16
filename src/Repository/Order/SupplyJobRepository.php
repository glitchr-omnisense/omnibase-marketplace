<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order\SupplyJob;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<SupplyJob> */
class SupplyJobRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, SupplyJob::class);
    }
}
