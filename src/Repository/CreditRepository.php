<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Credit;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Credit> */
class CreditRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Credit::class);
    }
}
