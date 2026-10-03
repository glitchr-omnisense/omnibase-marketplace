<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Quote\QuoteRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The business quotes, numbered D-<year>-<n> (saveNumbered()), a client's
 * (findForClient()), by status (pipeline()).
 *
 * @extends ServiceEntityRepository<Quote>
 */
class QuoteRepository extends ServiceEntityRepository
{
    use QuoteRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    protected function quotePrefix(): string
    {
        return 'D';
    }
}
