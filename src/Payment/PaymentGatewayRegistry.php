<?php

namespace Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every gateway of the application, by name(). */
class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /** @param iterable<PaymentGatewayInterface> $gateways */
    public function __construct(#[AutowireIterator('marketplace.payment_gateway')] iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway::name()] = $gateway;
        }
    }

    public function get(?string $name): ?PaymentGatewayInterface
    {
        return null !== $name ? ($this->gateways[$name] ?? null) : null;
    }

    public function for(PaymentMethod $method): ?PaymentGatewayInterface
    {
        return $this->get($method->getGatewayFactory());
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->gateways);
    }

    /**
     * The payment methods, among $methods, that can take this order: a
     * gateway exists for them and accepts it.
     *
     * @param iterable<PaymentMethod> $methods
     * @return PaymentMethod[]
     */
    public function usableFor(Order $order, iterable $methods): array
    {
        $usable = [];
        foreach ($methods as $method) {
            // A method may be limited to some currencies in its gateway
            // parameters ({"currencies": ["EUR"]}): a bank transfer has no
            // business on an order paid in an in-game coin.
            $currencies = array_map('strtoupper', (array) ($method->getGatewayParameters()['currencies'] ?? []));
            if ($currencies && !in_array(strtoupper((string) $order->getCurrency()), $currencies, true)) {
                continue;
            }

            $gateway = $this->for($method);
            if ($gateway && $gateway->supports($order, $method)) {
                $usable[] = $method;
            }
        }

        return $usable;
    }
}
