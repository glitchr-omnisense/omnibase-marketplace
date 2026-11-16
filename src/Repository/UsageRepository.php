<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Usage;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Usage> */
class UsageRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Usage::class);
    }
}
