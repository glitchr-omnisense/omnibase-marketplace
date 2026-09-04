<?php

namespace Base\Market\Repository\Product\Attribute\Adapter;

use Base\Market\Entity\Product\Attribute\Adapter\BarcodeAdapter;
use Base\Repository\Layout\AttributeRepository;

/**
 * @method BarcodeAdapter|null find($id, $lockMode = null, $lockVersion = null)
 * @method BarcodeAdapter|null findOneBy(array $criteria, array $orderBy = null)
 * @method BarcodeAdapter[]    findAll()
 * @method BarcodeAdapter[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BarcodeAdapterRepository extends AttributeRepository
{
}
