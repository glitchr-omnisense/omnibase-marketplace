<?php

namespace Base\Marketplace\Model;

/**
 * Whoever sells the thing.
 *
 * The marketplace needs a seller on a product, a store and an order, but it
 * must not name the concrete class: in this shop that is
 * App\Entity\User\Merchant, a subclass of the APP's User, and a bundle cannot
 * reach into an app's user hierarchy without pinning every consumer to one
 * shop's model.
 *
 * Doctrine associations point at this interface and are bound to a real class
 * per application through resolve_target_entities - see the host's
 * config/packages/doctrine.yaml. That is what lets Order::$managers stay a
 * mapped relation without the bundle knowing what a Merchant is.
 *
 * Kept to what the marketplace actually asks of a seller - its trading name,
 * used for the brand shown on a product - rather than mirroring the whole
 * entity, so implementing it stays cheap.
 */
interface MerchantInterface
{
    public function getCompanyName(): ?string;
}
