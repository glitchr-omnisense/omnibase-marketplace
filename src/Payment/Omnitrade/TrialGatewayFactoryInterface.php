<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Omnitrade\GatewayFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A glitchr/omnitrade gateway factory of the application's own whose gateways
 * move no money and reach no provider: accounts ready at once, a payment
 * paid on the spot by nobody, a product read from nowhere.
 *
 * In a demonstration (glitchr/omnibase's `demo` environment) these are the
 * only omnitrade gateways the marketplace talks to: OmnitradeGateways gives
 * the gateway such a factory makes, asked by the factory's name, and none of
 * omnitrade.gateways - whatever keys they hold. An application that wants
 * its lists to take contributions in demo writes one and names it
 * (marketplace.wishlist.payout_gateway under when@demo):
 *
 *     #[When(env: 'demo')]
 *     final class TrialGatewayFactory implements TrialGatewayFactoryInterface
 *     {
 *         public function getName(): string { return 'essai'; }
 *         public function create(array $options = []): GatewayInterface { return new Gateway('essai', 'Paiement d’essai', [new TrialAction()]); }
 *     }
 *
 * Implementing this is a promise: the marketplace cannot check what a
 * gateway does. Outside `demo` the interface changes nothing.
 */
#[AutoconfigureTag('marketplace.trial_gateway_factory')]
interface TrialGatewayFactoryInterface extends GatewayFactoryInterface
{
}
