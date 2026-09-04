<?php

namespace Base\Market\Repository\Sales\Attribute\Scope;

use Base\Market\Entity\Sales\Attribute\Scope\RegionAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method RegionAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method RegionAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method RegionAdapter[]    findAll()
 * @method RegionAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RegionAdapterRepository extends ServiceEntityRepository
{
}
