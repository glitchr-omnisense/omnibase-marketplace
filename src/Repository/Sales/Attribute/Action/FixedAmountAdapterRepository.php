<?php

namespace Base\Market\Repository\Sales\Attribute\Action;

use Base\Market\Entity\Sales\Attribute\Action\FixedAmountAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method FixedAmountAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method FixedAmountAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method FixedAmountAdapter[]    findAll()
 * @method FixedAmountAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FixedAmountAdapterRepository extends ServiceEntityRepository
{
}
