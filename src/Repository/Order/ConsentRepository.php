<?php

namespace Base\Marketplace\Repository\Order;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Consent;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Repository\Finders;

/** @extends ServiceEntityRepository<Consent> */
class ConsentRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Consent::class);
    }

    /** @return list<Consent> the statements made for the order, in the order they were shown */
    public function ofOrder(Order $order): array
    {
        if (null === $order->getId()) {
            return [];
        }

        return $this->findBy(['order' => Key::of($order)], ['id' => 'ASC']);
    }
}
