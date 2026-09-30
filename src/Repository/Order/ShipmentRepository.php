<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order\Shipment;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Shipment|null find($id, $lockMode = null, $lockVersion = null)
 * @method Shipment|null findOneBy(array $criteria, array $orderBy = null)
 * @method Shipment[]    findAll()
 * @method Shipment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ShipmentRepository extends ServiceEntityRepository
{
}
