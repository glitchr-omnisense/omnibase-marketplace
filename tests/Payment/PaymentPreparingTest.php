<?php

namespace Tests\Base\Marketplace\Payment;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductKind;
use Base\Marketplace\Event\PaymentPreparingEvent;
use Base\Marketplace\Model\PlanTerms;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Doctrine\Common\Collections\ArrayCollection;
use Omnitrade\Action\ActionInterface;
use Omnitrade\Gateway;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction as Paid;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;
use Omnitrade\Request\Subscribe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Before an order goes to its provider: a listener sends it to a connected
 * account and keeps a fee, an order of one recurring plan becomes a
 * subscription. Both need glitchr/omnitrade's Connect and subscriptions.
 */
final class PaymentPreparingTest extends TestCase
{
    /** @var list<Request> */
    private array $requests = [];

    protected function setUp(): void
    {
        if (!class_exists(Subscribe::class) || !property_exists(Payment::class, 'destination')) {
            self::markTestSkipped('Needs glitchr/omnitrade with Connect and subscriptions.');
        }
    }

    private function bridge(?EventDispatcher $dispatcher = null): OmnitradeGateway
    {
        $requests = &$this->requests;
        $action = new class($requests) implements ActionInterface {
            public function __construct(private array &$requests)
            {
            }

            public function supports(Request $request): bool
            {
                return $request instanceof Purchase || $request instanceof Subscribe;
            }

            public function execute(Request $request): void
            {
                $this->requests[] = $request;
                $request->setResult(new Paid('stub', 'cs_1', Status::PENDING, redirectUrl: 'https://pay.test/cs_1', raw: $request instanceof Subscribe ? ['subscription' => 'sub_1', 'customer' => 'cus_1'] : []));
            }
        };
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://shop.test/return');

        return new OmnitradeGateway('card', new Gateway('stub', 'Stub', [$action]), $urls, [], $dispatcher);
    }

    private function method(): PaymentMethod
    {
        return $this->createConfiguredMock(PaymentMethod::class, ['getGatewayParameters' => [], 'getSlug' => 'card', 'getGatewayFactory' => 'card']);
    }

    private function order(Product $product, int $quantity = 1): Order
    {
        $item = $this->createConfiguredMock(OrderItem::class, ['getProduct' => $product, 'getCurrency' => 'EUR', 'getUnitPrice' => 5000, 'getQuantity' => $quantity]);

        return $this->createConfiguredMock(Order::class, ['getId' => 7, 'getReference' => 'CMD-7', 'getCurrency' => 'EUR', 'getNetPrice' => 5000 * $quantity, 'getItems' => new ArrayCollection([$item])]);
    }

    private function product(ProductKind $kind, ?PlanTerms $terms = null): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['__toString', 'isShippable'])->addMethods(['getSku'])->getMock();
        $product->method('__toString')->willReturn('Formule D');
        $product->setKind($kind);
        $product->setPlan($terms);

        return $product;
    }

    public function testAListenerSendsThePaymentToAConnectedAccount(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(PaymentPreparingEvent::class, static function (PaymentPreparingEvent $event): void {
            self::assertSame('card', $event->gateway);
            $event->destination = 'acct_1';
            $event->applicationFee = 150;
            $event->metadata['contribution'] = '12';
            $event->description = 'Participation';
            $event->locale = 'fr';
        });
        $result = $this->bridge($dispatcher)->pay($this->order($this->product(ProductKind::GOODS)), new Transaction(), $this->method());

        self::assertTrue($result->isRedirect());
        self::assertInstanceOf(Purchase::class, $this->requests[0]);
        $payment = $this->requests[0]->payment;
        self::assertSame('acct_1', $payment->destination);
        self::assertTrue($payment->applicationFee->equals(Money::of(150, 'EUR')));
        self::assertSame(['order' => 'CMD-7', 'method' => 'card', 'contribution' => '12'], $payment->metadata);
        self::assertSame('Participation', $payment->description);
        self::assertSame('fr', $payment->locale);
    }

    public function testWithoutAListenerThePaymentIsThePlatformsOwn(): void
    {
        $this->bridge()->pay($this->order($this->product(ProductKind::GOODS)), new Transaction(), $this->method());

        self::assertInstanceOf(Purchase::class, $this->requests[0]);
        self::assertNull($this->requests[0]->payment->destination);
        self::assertNull($this->requests[0]->payment->applicationFee);
    }

    public function testARecurringPlanIsSubscribedTo(): void
    {
        $transaction = new Transaction();
        $plan = $this->product(ProductKind::PLAN, new PlanTerms(PlanTerms::RECURRING, 'year'));
        $result = $this->bridge()->pay($this->order($plan), $transaction, $this->method());

        self::assertTrue($result->isRedirect());
        self::assertInstanceOf(Subscribe::class, $this->requests[0]);
        self::assertSame('year', $this->requests[0]->interval);
        self::assertSame('Formule D', $this->requests[0]->payment->description);
        self::assertSame('sub_1', $transaction->getDetails()['subscription'], 'kept for the Subscription made when it is paid');
        self::assertSame('cus_1', $transaction->getDetails()['customer']);
    }

    public function testAPassOrSeveralPlansArePaidOnce(): void
    {
        $this->bridge()->pay($this->order($this->product(ProductKind::PLAN, new PlanTerms(months: 12))), new Transaction(), $this->method());
        self::assertInstanceOf(Purchase::class, $this->requests[0]);

        $this->bridge()->pay($this->order($this->product(ProductKind::PLAN, new PlanTerms(PlanTerms::RECURRING, 'month')), 2), new Transaction(), $this->method());
        self::assertInstanceOf(Purchase::class, $this->requests[1], 'two of them are not one subscription');
    }
}
