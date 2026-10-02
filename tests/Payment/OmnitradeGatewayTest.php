<?php

namespace Tests\Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Payment\ManualGateway;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Gateway;
use Omnitrade\Model\Money;
use Omnitrade\Model\Refund;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction as Paid;
use Omnitrade\Registry;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Refund as RefundRequest;
use Omnitrade\Request\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bridge from a PaymentMethod to an omnitrade gateway: the order
 * described as a Payment, the provider's answer as a PaymentResult, its
 * reference kept on the Transaction.
 */
final class OmnitradeGatewayTest extends TestCase
{
    /** @var list<Request> */
    private array $requests = [];

    private function bridge(Paid $answer): OmnitradeGateway
    {
        $requests = &$this->requests;
        $action = new class($answer, $requests) implements ActionInterface {
            public function __construct(private readonly Paid $answer, private array &$requests)
            {
            }

            public function supports(Request $request): bool
            {
                return $request instanceof Purchase || $request instanceof FetchTransaction || $request instanceof RefundRequest;
            }

            public function execute(Request $request): void
            {
                $this->requests[] = $request;
                $request->setResult($request instanceof RefundRequest ? new Refund('stub', 're_1', $request->amount ?? Money::of(0, 'EUR'), Status::REFUNDED, $request->reference) : $this->answer);
            }
        };
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p) => 'https://shop.test/'.$route.'?'.http_build_query($p));

        return new OmnitradeGateway('card', new Gateway('stub', 'Stub', [$action]), $urls);
    }

    /** A method whose settings need no container: the entity reads them from the parameter bag. */
    private function method(): PaymentMethod
    {
        return $this->createConfiguredMock(PaymentMethod::class, ['getGatewayParameters' => [], 'getSlug' => 'card', 'getGatewayFactory' => 'card']);
    }

    private function order(): Order
    {
        $mug = $this->createMock(Product::class);
        $mug->method('__toString')->willReturn('Mug');
        $mug->method('isShippable')->willReturn(true);
        $item = $this->createMock(OrderItem::class);
        $item->method('getProduct')->willReturn($mug);
        $item->method('getCurrency')->willReturn('EUR');
        $item->method('getUnitPrice')->willReturn(1290);
        $item->method('getQuantity')->willReturn(2);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getReference')->willReturn('CMD-1042');
        $order->method('getCurrency')->willReturn('EUR');
        $order->method('getNetPrice')->willReturn(2580);
        $order->method('getItems')->willReturn(new ArrayCollection([$item]));

        return $order;
    }

    public function testAPaidAnswerPaysTheOrderAndKeepsTheReference(): void
    {
        $bridge = $this->bridge(new Paid('stub', 'tx_1', Status::PAID, Money::of(2580, 'EUR'), method: 'card'));
        $transaction = new Transaction();

        $result = $bridge->pay($this->order(), $transaction, $this->method());

        self::assertTrue($result->isPaid());
        self::assertSame('tx_1', $transaction->getWebhook());
        self::assertSame(['gateway' => 'card', 'provider' => 'stub', 'reference' => 'tx_1', 'status' => 'paid', 'method' => 'card'], $transaction->getDetails());

        $payment = $this->requests[0]->payment;
        self::assertSame(2580, $payment->amount->amount);
        self::assertSame('CMD-1042', $payment->reference);
        self::assertCount(1, $payment->lines, 'the lines add up to the net price: sent as they are');
        self::assertSame(2, $payment->lines[0]->quantity);
        self::assertStringStartsWith('https://shop.test/marketplace_payment_return?order=42&gateway=card', $payment->returnUrl);
        self::assertStringContainsString('cancel=1', $payment->cancelUrl);
    }

    public function testAHostedPageIsARedirectAndARefusalARefusal(): void
    {
        $bridge = $this->bridge(new Paid('stub', 'cs_1', Status::PENDING, redirectUrl: 'https://pay.test/cs_1'));
        $result = $bridge->pay($this->order(), new Transaction(), $this->method());
        self::assertTrue($result->isRedirect());
        self::assertSame('https://pay.test/cs_1', $result->redirectUrl);

        $bridge = $this->bridge(new Paid('stub', 'tx_2', Status::REFUSED, message: 'card_declined'));
        $result = $bridge->pay($this->order(), new Transaction(), $this->method());
        self::assertTrue($result->isRefused());
        self::assertSame('payment.refused', $result->reason);
        self::assertSame(['{reason}' => 'card_declined'], $result->parameters);
    }

    public function testLinesThatDoNotAddUpBecomeOneLineOfTheNetPrice(): void
    {
        $order = $this->order();
        $order = $this->createConfiguredMock(Order::class, ['getId' => 42, 'getReference' => 'CMD-1043', 'getCurrency' => 'EUR', 'getNetPrice' => 2990, 'getItems' => $order->getItems()]);
        $this->bridge(new Paid('stub', 'tx_3', Status::PAID))->pay($order, new Transaction(), $this->method());

        $lines = $this->requests[0]->payment->lines;
        self::assertCount(1, $lines);
        self::assertSame(2990, $lines[0]->unitAmount->amount, 'shipping and VAT live on the order');
        self::assertSame('CMD-1043', $lines[0]->label);
    }

    public function testFetchAndRefundGoByTheKeptReference(): void
    {
        $bridge = $this->bridge(new Paid('stub', 'tx_4', Status::PAID, Money::of(2580, 'EUR')));
        $transaction = (new Transaction())->setWebhook('tx_4');

        self::assertSame(Status::PAID, $bridge->fetch($transaction)->status);
        self::assertSame('tx_4', $this->requests[0]->reference);

        self::assertSame('re_1', $bridge->refund($transaction, 500, 'EUR', 'refund-1', 'too many mugs'));
        self::assertSame(500, $this->requests[1]->amount->amount);
        self::assertSame('refund-1', $this->requests[1]->idempotencyKey);
    }

    public function testTheRegistryFallsBackToTheOmnitradeGateways(): void
    {
        $factory = new class() extends \Omnitrade\GatewayFactory {
            protected function populateConfig(\Omnitrade\Config $config): void
            {
                $config->defaults(['omnitrade.factory_name' => 'stub', 'omnitrade.factory_title' => 'Stub']);
            }
        };
        $omnitrade = new OmnitradeGateways(new Registry([$factory], ['card' => ['factory' => 'stub'], 'paypal' => ['factory' => 'stub']]), $this->createMock(UrlGeneratorInterface::class));
        $registry = new PaymentGatewayRegistry([new ManualGateway()], $omnitrade);

        self::assertSame(['manual', 'card', 'paypal'], $registry->names());
        self::assertInstanceOf(ManualGateway::class, $registry->get('manual'));
        self::assertInstanceOf(OmnitradeGateway::class, $registry->get('card'));
        self::assertSame($registry->get('card'), $registry->get('card'), 'built once');
        self::assertSame('card', $registry->get('card')->getName());
        self::assertNull($registry->get('stripe'));
    }
}
