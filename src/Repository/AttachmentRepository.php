<?php

namespace Base\Marketplace\Repository;

use Base\Marketplace\Entity\Attachment;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Attachment> */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Attachment::class);
    }
}
