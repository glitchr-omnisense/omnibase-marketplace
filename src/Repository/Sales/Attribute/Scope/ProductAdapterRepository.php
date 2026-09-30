<?php

namespace Base\Marketplace\Repository\Sales\Attribute\Scope;

use Base\Marketplace\Entity\Sales\Attribute\Scope\ProductAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method ProductAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProductAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProductAdapter[]    findAll()
 * @method ProductAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProductAdapterRepository extends ServiceEntityRepository
{
}
