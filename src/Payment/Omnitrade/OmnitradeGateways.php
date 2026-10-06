<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Base\Service\SettingBagInterface;
use Omnitrade\Exception\InvalidConfigException;
use Omnitrade\Registry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
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
 *
 * In a demonstration (glitchr/omnibase's `demo` environment) no money moves
 * and no provider is reached - whoever asks: the checkout, a list's
 * contributions, its owner's payout account, an address read for a wish all
 * come here. There, none of omnitrade.gateways answers, whatever keys it
 * holds, typed or configured: get() gives the gateway made by a factory the
 * application marked as a trial one (TrialGatewayFactoryInterface), asked by
 * that factory's name, and null for everything else - which every caller
 * already takes as "no such gateway". A real gateway asked for is written to
 * the log, once.
 */
final class OmnitradeGateways
{
    public const SETTINGS = 'api.payment_method';

    /** glitchr/omnibase's demonstration environment (Base\Demo\DemoMode::ENVIRONMENT). */
    private const DEMO_ENVIRONMENT = 'demo';

    /** @var array<string, ?OmnitradeGateway> by name and typed options */
    private array $bridges = [];

    /** @var array<string, TrialGatewayFactoryInterface> the application's trial factories, by name */
    private array $trials = [];

    /** @var array<string, true> the real gateways refused in this demonstration, each said once */
    private array $refused = [];

    /** @param iterable<TrialGatewayFactoryInterface> $trials */
    public function __construct(
        private readonly Registry $registry,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?SettingBagInterface $settings = null,
        private readonly ?\Psr\EventDispatcher\EventDispatcherInterface $dispatcher = null,
        #[Autowire('%kernel.environment%')] private readonly string $environment = 'prod',
        #[AutowireIterator('marketplace.trial_gateway_factory')] iterable $trials = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($trials as $factory) {
            $this->trials[$factory->getName()] = $factory;
        }
    }

    /** Whether this is a demonstration: only the application's trial gateways answer. */
    public function isDemonstration(): bool
    {
        return self::DEMO_ENVIRONMENT === $this->environment;
    }

    /**
     * The bridge to the gateway of that name; null when there is none, or
     * when it lacks what it needs to run (no API key yet, typed or
     * configured): such a method is simply not offered at checkout. In a
     * demonstration, null for every gateway but the application's trial ones.
     */
    public function get(string $name): ?OmnitradeGateway
    {
        if ($this->isDemonstration()) {
            return $this->trial($name);
        }
        if (!$this->registry->has($name)) {
            return null;
        }
        $typed = $this->typed($name);
        $key = $name.($typed ? '#'.md5(serialize($typed)) : '');
        if (\array_key_exists($key, $this->bridges)) {
            return $this->bridges[$key];
        }

        try {
            $gateway = $typed ? $this->registry->create($name, $typed) : $this->registry->get($name);
        } catch (InvalidConfigException) {
            return $this->bridges[$key] = null;
        }

        return $this->bridges[$key] = new OmnitradeGateway($name, $gateway, $this->urls, array_replace($this->registry->options($name), $typed), $this->dispatcher);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->isDemonstration() ? array_keys($this->trials) : $this->registry->names();
    }

    /**
     * In a demonstration: the gateway a trial factory of that name makes -
     * built from the factory itself, never through omnitrade.gateways - or
     * nothing. No option applies to it, typed or configured.
     */
    private function trial(string $name): ?OmnitradeGateway
    {
        if (\array_key_exists($name, $this->bridges)) {
            return $this->bridges[$name];
        }
        if (!isset($this->trials[$name])) {
            if ($this->registry->has($name) && !isset($this->refused[$name])) {
                $this->refused[$name] = true;
                $this->logger?->warning('Demonstration: the omnitrade gateway "{gateway}" was asked for and refused - no provider is reached in demo.', ['gateway' => $name]);
            }

            return null;
        }

        return $this->bridges[$name] = new OmnitradeGateway($name, $this->trials[$name]->create(), $this->urls, [], $this->dispatcher);
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
