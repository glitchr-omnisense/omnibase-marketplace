<?php

namespace Base\Market\Repository\Sales\Attribute\Action;

use Base\Market\Entity\Sales\Attribute\Action\PercentageAmountAdapter;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method PercentageAmountAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method PercentageAmountAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method PercentageAmountAdapter[]    findAll()
 * @method PercentageAmountAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PercentageAmountAdapterRepository extends ServiceEntityRepository
{
}
