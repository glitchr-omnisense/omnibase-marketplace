<?php

namespace Base\Market\Repository\Sales\Attribute\Scope;

use Base\Market\Entity\Sales\Attribute\Scope\StoreAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method StoreAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method StoreAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method StoreAdapter[]    findAll()
 * @method StoreAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StoreAdapterRepository extends ServiceEntityRepository
{
}
