<?php

namespace Base\Marketplace\Repository\Sales;

use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Marketplace\Entity\Sales\Region;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Region|null find($id, $lockMode = null, $lockVersion = null)
 * @method Region|null findOneBy(array $criteria, array $orderBy = null)
 * @method Region[]    findAll()
 * @method Region[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RegionRepository extends ServiceEntityRepository
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
