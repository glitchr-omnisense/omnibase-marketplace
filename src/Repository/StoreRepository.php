<?php

namespace Base\Marketplace\Repository;

use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Marketplace\Entity\Store;
use Base\Repository\ThreadRepository;

/**
 * @method Store|null find($id, $lockMode = null, $lockVersion = null)
 * @method Store|null findOneBy(array $criteria, array $orderBy = null)
 * @method Store[]    findAll()
 * @method Store[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StoreRepository extends ThreadRepository
{
    /**
     * The currencies its records are priced in, each once (null left out).
     *
     * @return list<string>
     */
    public function currencies(): array
    {
        return array_column($this->findValuesBy(['currency'], [Group::of(Parser::SEPARATOR_AND, Criterion::of('currency', null, Parser::OPTION_NOT_NULL))], distinct: true), 'currency');
    }
}
