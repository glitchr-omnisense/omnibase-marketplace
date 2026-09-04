<?php

namespace Base\Market\Repository\Sales\Attribute\Scope;

use Base\Market\Entity\Sales\Attribute\Scope\UserAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method UserAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserAdapter[]    findAll()
 * @method UserAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserAdapterRepository extends ServiceEntityRepository
{
}
