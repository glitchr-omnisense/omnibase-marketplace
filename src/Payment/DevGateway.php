<?php

namespace Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Paid by nobody, at once: an order walked all the way through in
 * development without a Stripe key - and in a demonstration, where no money
 * may move. Registered only when the kernel runs in debug or in the `demo`
 * environment (MarketplaceExtension), and supports() says no anywhere else
 * all the same, so a method on the "dev" gateway never shows in production.
 * A payment method (PaymentMethod) whose gateway factory is "dev" offers it -
 * created in the back office or in the fixtures.
 *
 * The applications' own copies (App\Market\DevGateway) are no longer needed.
 */
final class DevGateway implements PaymentGatewayInterface
{
    /** glitchr/omnibase's demonstration environment (Base\Demo\DemoMode::ENVIRONMENT). */
    public const DEMO_ENVIRONMENT = 'demo';

    public function __construct(
        #[Autowire('%kernel.debug%')] private readonly bool $debug = false,
        #[Autowire('%kernel.environment%')] private readonly string $environment = 'prod',
    ) {
    }

    public static function name(): string
    {
        return 'dev';
    }

    public function supports(Order $order, PaymentMethod $method): bool
    {
        return $this->debug || self::DEMO_ENVIRONMENT === $this->environment;
    }

    public function pay(Order $order, Transaction $transaction, PaymentMethod $method): PaymentResult
    {
        $transaction->setDetails([
            'dev' => true,
            'amount' => (int) $order->getNetPrice(),
            'currency' => (string) $order->getCurrency(),
        ]);

        return PaymentResult::paid();
    }
}
