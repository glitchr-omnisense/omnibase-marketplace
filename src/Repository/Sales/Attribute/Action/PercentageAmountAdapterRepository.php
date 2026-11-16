<?php

namespace Base\Marketplace\Repository\Sales\Attribute\Action;

use Base\Marketplace\Entity\Sales\Attribute\Action\PercentageAmountAdapter;
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
