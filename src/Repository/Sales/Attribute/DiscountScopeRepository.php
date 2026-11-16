<?php

namespace Base\Marketplace\Repository\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Attribute\DiscountScope;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method DiscountScope|null find($id, $lockMode = null, $lockVersion = null)
 * @method DiscountScope|null findOneBy(array $criteria, array $orderBy = null)
 * @method DiscountScope[]    findAll()
 * @method DiscountScope[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DiscountScopeRepository extends ServiceEntityRepository
{
}
