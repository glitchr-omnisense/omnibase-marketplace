<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Base\Service\SettingBagInterface;
use Omnitrade\Registry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bridges to glitchr/omnitrade's configured gateways, one per name.
 * Registered only when glitchr/omnitrade is installed (config/services.php);
 * PaymentGatewayRegistry asks here after the application's own gateways.
 *
 * A gateway's options may also be typed in the back office, as settings
 * under api.payment_method.<gateway>.<option> - the Stripe keys of the
 * "stripe" gateway are api.payment_method.stripe.api_key and
 * api.payment_method.stripe.webhook_secret, a PayPal secret
 * api.payment_method.paypal.secret. Typed, they win over the configuration
 * (config/packages/omnitrade.yaml, the environment); empty, the
 * configuration serves. Any provider, any option: nothing to write per
 * provider.
 */
final class OmnitradeGateways
{
    public const SETTINGS = 'api.payment_method';

    /** @var array<string, OmnitradeGateway> by name and typed options */
    private array $bridges = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?SettingBagInterface $settings = null,
    ) {
    }

    public function get(string $name): ?OmnitradeGateway
    {
        if (!$this->registry->has($name)) {
            return null;
        }
        $typed = $this->typed($name);
        $key = $name.($typed ? '#'.md5(serialize($typed)) : '');

        return $this->bridges[$key] ??= new OmnitradeGateway(
            $name,
            $typed ? $this->registry->create($name, $typed) : $this->registry->get($name),
            $this->urls,
            array_replace($this->registry->options($name), $typed),
        );
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->registry->names();
    }

    /**
     * The options typed in the back office for this gateway, the empty ones left out.
     *
     * @return array<string, string>
     */
    public function typed(string $name): array
    {
        if (null === $this->settings) {
            return [];
        }
        try {
            $tree = $this->settings->get(self::SETTINGS.'.'.$name);
        } catch (\Throwable) {
            return []; // no database yet: the configuration serves
        }

        $typed = [];
        foreach ($tree as $option => $node) {
            $value = \is_array($node) ? ($node['_self'] ?? null) : null;
            if ('_self' !== $option && \is_scalar($value) && '' !== trim((string) $value)) {
                $typed[(string) $option] = trim((string) $value);
            }
        }

        return $typed;
    }
}
