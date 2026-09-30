<?php

namespace Base\Marketplace\Repository\Product;

use Base\Marketplace\Entity\Product\Variant;
use Base\Marketplace\Repository\ProductRepository;

/**
 * @method Variant|null find($id, $lockMode = null, $lockVersion = null)
 * @method Variant|null findOneBy(array $criteria, array $orderBy = null)
 * @method Variant[]    findAll()
 * @method Variant[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VariantRepository extends ProductRepository
{
}
