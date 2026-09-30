<?php

namespace Base\Marketplace\Repository\Sales\Attribute\Scope;

use Base\Marketplace\Entity\Sales\Attribute\Scope\OrderAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method OrderAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method OrderAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method OrderAdapter[]    findAll()
 * @method OrderAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class OrderAdapterRepository extends ServiceEntityRepository
{
}
