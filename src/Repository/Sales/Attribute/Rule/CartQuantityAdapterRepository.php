<?php

namespace Base\Market\Repository\Sales\Attribute\Rule;

use Base\Market\Entity\Sales\Attribute\Rule\CartQuantityAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method CartQuantityAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method CartQuantityAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method CartQuantityAdapter[]    findAll()
 * @method CartQuantityAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CartQuantityAdapterRepository extends ServiceEntityRepository
{
}
