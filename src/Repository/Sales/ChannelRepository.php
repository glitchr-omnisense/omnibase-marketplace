<?php

namespace Base\Market\Repository\Sales;

use Base\Market\Entity\Sales\Channel;
use Base\Annotations\Traits\HierarchifyTrait;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Channel|null find($id, $lockMode = null, $lockVersion = null)
 * @method Channel|null findOneBy(array $criteria, array $orderBy = null)
 * @method Channel[]    findAll()
 * @method Channel[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChannelRepository extends ServiceEntityRepository
{
    use HierarchifyTrait;
}
