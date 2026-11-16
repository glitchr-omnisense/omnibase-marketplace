<?php

namespace Base\Marketplace\Repository\Product\Attribute;

use Base\Marketplace\Entity\Product\Attribute\Barcode;
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
