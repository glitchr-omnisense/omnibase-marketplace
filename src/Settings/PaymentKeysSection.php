<?php

namespace Base\Marketplace\Settings;

use Base\Admin\Settings\SettingsSectionInterface;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The shop's keys on omnibase/admin's API keys page: Stripe's, when the
 * "stripe" gateway of glitchr/omnitrade is there. Typed there, they win over
 * the configuration (api.payment_method.<gateway>.<option>, read by
 * Payment\Omnitrade\OmnitradeGateways) - the applications no longer list
 * them in a SystemController of their own.
 */
#[AsTaggedItem(priority: 50)]
final class PaymentKeysSection implements SettingsSectionInterface
{
    public function __construct(private readonly ?\Omnitrade\Registry $gateways = null)
    {
    }

    public function getPage(): string
    {
        return self::API_KEYS;
    }

    public function getFields(): array
    {
        if (!$this->gateways?->has('stripe')) {
            return [];
        }
        $path = OmnitradeGateways::SETTINGS.'.stripe.';

        return [
            $path.'publishable' => ['required' => false, 'label' => 'Stripe — clé publique (pk_…)'],
            $path.'api_key' => ['required' => false, 'label' => 'Stripe — clé secrète (sk_test_… / sk_live_…)'],
            $path.'webhook_secret' => ['required' => false, 'label' => 'Stripe — secret du webhook (whsec_…)'],
        ];
    }
}
