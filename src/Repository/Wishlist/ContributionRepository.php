<?php

namespace Base\Marketplace\Repository\Wishlist;

use Base\Marketplace\Entity\Wishlist\Contribution;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Contribution> */
class ContributionRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Contribution::class);
    }
}
