<?php

namespace Base\Marketplace\Repository\Sales;

use Base\Marketplace\Entity\Sales\Channel;
use Base\Attributes\Traits\HierarchifyTrait;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Channel|null find($id, $lockMode = null, $lockVersion = null)
 * @method Channel|null findOneBy(array $criteria, array $orderBy = null)
 * @method Channel[]    findAll()
 * @method Channel[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ChannelRepository extends ServiceEntityRepository
{
    use \Base\Attributes\Traits\HierarchifyTrait;
}
