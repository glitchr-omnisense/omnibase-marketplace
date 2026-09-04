<?php

namespace Base\Market\Repository\Sales\Attribute\Rule;

use Base\Market\Entity\Sales\Attribute\Rule\CartContainsAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method CartContainsAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method CartContainsAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method CartContainsAdapter[]    findAll()
 * @method CartContainsAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CartContainsAdapterRepository extends ServiceEntityRepository
{
}
