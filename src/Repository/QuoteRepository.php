<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Quote\QuoteRepositoryTrait;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/**
 * The business quotes, numbered D-<year>-<n> (saveNumbered()), a client's
 * (findForClient()), by status (pipeline()).
 *
 * @extends ServiceEntityRepository<Quote>
 */
class QuoteRepository extends ServiceEntityRepository
{
    use QuoteRepositoryTrait;

    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Quote::class);
    }

    public function quotePrefix(): string
    {
        return 'D';
    }
}
