<?php

namespace Base\Market\Repository\Product\Attribute;

use Base\Market\Entity\Product\Attribute\Barcode;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Barcode|null find($id, $lockMode = null, $lockVersion = null)
 * @method Barcode|null findOneBy(array $criteria, array $orderBy = null)
 * @method Barcode[]    findAll()
 * @method Barcode[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BarcodeRepository extends ServiceEntityRepository
{
}
