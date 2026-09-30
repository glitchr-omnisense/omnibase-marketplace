<?php

namespace Base\Marketplace\Repository\Sales\Attribute;

use Base\Marketplace\Entity\Sales\Attribute\FeeAction;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method FeeAction|null find($id, $lockMode = null, $lockVersion = null)
 * @method FeeAction|null findOneBy(array $criteria, array $orderBy = null)
 * @method FeeAction[]    findAll()
 * @method FeeAction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FeeRuleRepository extends ServiceEntityRepository
{
}
