<?php

namespace Base\Marketplace\Repository\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Attribute\FeeScope;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method FeeScope|null find($id, $lockMode = null, $lockVersion = null)
 * @method FeeScope|null findOneBy(array $criteria, array $orderBy = null)
 * @method FeeScope[]    findAll()
 * @method FeeScope[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FeeScopeRepository extends ServiceEntityRepository
{
}
