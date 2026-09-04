<?php

namespace Base\Market\Repository\Sales\Attribute;

use Base\Market\Entity\Sales\Attribute\FeeAction;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method FeeAction|null find($id, $lockMode = null, $lockVersion = null)
 * @method FeeAction|null findOneBy(array $criteria, array $orderBy = null)
 * @method FeeAction[]    findAll()
 * @method FeeAction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FeeActionRepository extends ServiceEntityRepository
{
}
