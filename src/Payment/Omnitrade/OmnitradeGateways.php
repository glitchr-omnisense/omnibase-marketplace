<?php

namespace Base\Marketplace\Payment\Omnitrade;

use Omnitrade\Registry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bridges to glitchr/omnitrade's configured gateways, one per name,
 * built once. Registered only when glitchr/omnitrade is installed
 * (config/services.php); PaymentGatewayRegistry asks here after the
 * application's own gateways.
 */
final class OmnitradeGateways
{
    /** @var array<string, OmnitradeGateway> */
    private array $bridges = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function get(string $name): ?OmnitradeGateway
    {
        if (!$this->registry->has($name)) {
            return null;
        }

        return $this->bridges[$name] ??= new OmnitradeGateway($name, $this->registry->get($name), $this->urls);
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->registry->names();
    }
}
