<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Entitlement;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Entitlement> */
class EntitlementRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Entitlement::class);
    }
}
