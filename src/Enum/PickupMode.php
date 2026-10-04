<?php

namespace Base\Marketplace\Enum;

/** How an order is handed over: collected at the shop on a slot, brought nearby by the shop itself, or sent by a carrier. */
enum PickupMode: string
{
    case PICKUP = 'pickup';
    case DELIVERY = 'delivery';
    case SHIPPING = 'shipping';

    public function trans(): string
    {
        return 'pickup.mode.'.$this->value;
    }

    /** Whether somebody travels to the customer: an address is needed. */
    public function needsAddress(): bool
    {
        return self::PICKUP !== $this;
    }
}
