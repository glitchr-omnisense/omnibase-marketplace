<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Event\QuickOrderDoneEvent;
use Base\Marketplace\Service\QuickOrder;
use Base\Marketplace\Twig\QuickOrderTwigExtension;
use Base\Service\FormGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A product bought in one step, as a visitor buys it: the form of
 * @Marketplace/client/_quick_order.html.twig (Form\QuickOrderType, guarded by
 * the core's option `guard`) posted with an e-mail address and no account,
 * the order made with its lines, paid with the trial payment ("dev": paid by
 * nobody, at once) or waiting (a bank transfer), the buyer back from the
 * provider on the order's signed page, QuickOrderDoneEvent and OrderPaidEvent.
 */
final class QuickOrderTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    /** @var list<int> */
    private array $methods = [];

    /** @var array<string, string> the session cookie of the last buy() */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(FormGuard::class)) {
            self::markTestSkipped('The host\'s glitchr/omnibase has no forms\' guard.');
        }
        if ([] !== $this->entityManager->getRepository(PaymentMethod::class)->findAll()) {
            self::markTestSkipped('The host has payment methods of its own: which one takes a quick order would be theirs to say.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->methods as $id) {
            if ($method = $this->entityManager->find(PaymentMethod::class, $id)) {
                foreach ($this->entityManager->getRepository(Order::class)->findBy(['paymentMethod' => $method]) as $order) {
                    $order->setPaymentMethod(null);
                }
                $this->entityManager->remove($method);
            }
        }
        $this->entityManager->flush();
        parent::tearDown();
    }

    private function method(string $gateway): PaymentMethod
    {
        $method = new PaymentMethod();
        $method->setSlug($gateway.'-'.bin2hex(random_bytes(3)));
        $method->setLabel($gateway);
        $method->setGatewayFactory($gateway);
        $this->entityManager->persist($method);
        $this->entityManager->flush();
        $this->methods[] = $method->getId();

        return $method;
    }

    /**
     * The form as the page shows it, in the visitor's session: its fields as posted, and the session's cookie.
     *
     * @return array{array<string, mixed>, array<string, string>, string}
     */
    private function form(Product $product): array
    {
        $request = Request::create('/boutiques');
        $session = self::getContainer()->get('session.factory')->createSession();
        $request->setSession($session);
        $stack = self::getContainer()->get('request_stack');
        $stack->push($request);
        $html = self::getContainer()->get('twig')->render('@Marketplace/client/_quick_order.html.twig', ['product' => $product]);
        $view = self::getContainer()->get(QuickOrderTwigExtension::class)->view($product, '/boutiques');
        $stack->pop();
        $session->save();

        $fields = ['back' => '/boutiques', 'guard_website' => '', 'guard_opened' => self::getContainer()->get(FormGuard::class)->stamp(time() - 10)];
        // The CSRF token's field: _token, or _csrf_token where the host's core names it so.
        foreach (['_token', '_csrf_token'] as $name) {
            if (isset($view[$name])) {
                $fields[$name] = $view[$name]->vars['value'];
            }
        }

        return [$fields, [$session->getName() => $session->getId()], $html];
    }

    /** @param array<string, string> $fields over the form's own */
    private function buy(Product $product, array $fields): Response
    {
        [$form, $cookies] = $this->form($product);
        $this->cookies = $cookies;
        $post = [
            'quick_order' => $fields + $form,
            // glitchr/omnishield's "fixed" test gateway, where the host has it: its token, outside the form.
            (class_exists(\Omnishield\Testing\FixedGateway::class) ? \Omnishield\Testing\FixedGateway::FIELD : 'omniguard-token') => (class_exists(\Omnishield\Testing\FixedGateway::class) ? \Omnishield\Testing\FixedGateway::TOKEN : 'omniguard-fixed-token'),
        ];

        return self::$kernel->handle(Request::create('/commande-express/'.$product->getId(), 'POST', $post, $cookies, [], ['HTTP_ORIGIN' => 'http://localhost']));
    }

    /** What the shop said to the visitor (the flashes waiting in their session), for a failure's message. */
    private function said(array $cookies): string
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->setId((string) reset($cookies));
        $session->start();

        return json_encode($session->getFlashBag()->peekAll(), \JSON_UNESCAPED_UNICODE);
    }

    private function lastOrder(string $email): ?Order
    {
        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 5) as $order) {
            if ($order->getCustomer()?->getEmail() === $email) {
                return $order;
            }
        }

        return null;
    }

    public function testTheFormIsGuardedAndAsksAnAddress(): void
    {
        [, , $html] = $this->form($this->goods());

        self::assertStringContainsString('name="quick_order[email]"', $html);
        self::assertStringContainsString('name="quick_order[guard_website]"', $html, 'the guard\'s trap');
        self::assertStringContainsString('name="quick_order[guard_opened]"', $html, 'the guard\'s stamp');
        self::assertStringNotContainsString('name="website"', $html, 'no trap of the form\'s own');
        self::assertStringContainsString('action="/commande-express/', $html);
    }

    public function testAnAddressBuysAndTheTrialPaymentPaysAtOnce(): void
    {
        $this->method('dev');
        $product = $this->goods('pot', 2400);
        $paid = [];
        self::getContainer()->get('event_dispatcher')->addListener(OrderPaidEvent::class, function (OrderPaidEvent $event) use (&$paid): void {
            $paid[] = $event->order->getReference();
        });
        $email = 'acheteur-'.bin2hex(random_bytes(3)).'@example.org';

        $response = $this->buy($product, ['email' => strtoupper($email)]);
        $cookies = $this->cookies;
        self::assertTrue($response->isRedirect(), substr(strip_tags((string) $response->getContent()), 0, 1500));
        $done = (string) $response->headers->get('Location');
        self::assertStringContainsString('/commande-express/merci/', $done, $this->said($cookies));
        self::assertStringContainsString('_hash=', $done, 'a signed link');

        $order = $this->lastOrder($email);
        self::assertNotNull($order, 'the order, for that address (lower-cased)');
        self::assertNotNull($order->getPaidAt(), 'paid at once');
        self::assertFalse($order->isPending());
        self::assertCount(1, $order->getItems());
        self::assertSame($product->getId(), $order->getItems()->first()->getProduct()->getId());
        self::assertGreaterThan(0, (int) $order->getNetPrice());
        self::assertSame([$order->getReference()], $paid, 'delivered once, as any order');

        // The order's page, for whoever holds the link.
        $page = self::$kernel->handle(Request::create($done));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString((string) $order->getReference(), (string) $page->getContent());

        // An application's own page there (files to download, a pickup time).
        self::getContainer()->get('event_dispatcher')->addListener(QuickOrderDoneEvent::class, function (QuickOrderDoneEvent $event): void {
            $event->setResponse(new Response('Your files: '.$event->order->getReference()));
        });
        self::assertSame('Your files: '.$order->getReference(), (string) self::$kernel->handle(Request::create($done))->getContent());

        // A link forged or tampered with: a plain 403.
        self::assertSame(403, self::$kernel->handle(Request::create(str_replace('_hash=', '_hash=x', $done)))->getStatusCode());
        self::assertSame(403, self::$kernel->handle(Request::create('/commande-express/merci/'.$order->getReference()))->getStatusCode());
    }

    public function testThePaymentThatWaitsEndsOnTheOrdersPageAndTheReturnComesBackThere(): void
    {
        $this->method('manual');
        $product = $this->goods('bol', 1500);
        $email = 'virement-'.bin2hex(random_bytes(3)).'@example.org';

        $response = $this->buy($product, ['email' => $email]);
        $cookies = $this->cookies;
        self::assertTrue($response->isRedirect(), substr(strip_tags((string) $response->getContent()), 0, 1500));
        self::assertStringContainsString('/commande-express/merci/', (string) $response->headers->get('Location'), $this->said($cookies));
        $order = $this->lastOrder($email);
        self::assertNotNull($order);
        self::assertTrue($order->isPending(), 'waiting for the transfer');
        self::assertNull($order->getPaidAt());
        self::assertStringContainsString((string) $order->getReference(), (string) self::$kernel->handle(Request::create((string) $response->headers->get('Location')))->getContent());

        // Back from the provider in the same browser: the order's signed page, not the basket of somebody signed in.
        $return = self::$kernel->handle(Request::create('/panier/'.$order->getId().'/paiement/manual', 'GET', [], $cookies));
        self::assertTrue($return->isRedirect(), (string) $return->getStatusCode());
        self::assertStringContainsString('/commande-express/merci/'.$order->getReference(), (string) $return->headers->get('Location'));
        self::assertSame(self::getContainer()->get(QuickOrder::class)->doneUrl($order), $return->headers->get('Location'));

        // Another browser, which made no such order: the shop's own return, to the basket.
        $stranger = self::$kernel->handle(Request::create('/panier/'.$order->getId().'/paiement/manual'));
        self::assertStringNotContainsString('/commande-express/merci/', (string) $stranger->headers->get('Location'));
    }

    public function testWhatTheGuardOrTheAddressRefusesMakesNothing(): void
    {
        $this->method('dev');
        $product = $this->goods('tasse', 900);
        $count = fn (): int => \count($this->entityManager->getRepository(Order::class)->findAll());
        $before = $count();

        foreach ([
            'a trap filled' => ['email' => 'robot@example.org', 'guard_website' => 'https://spam.example'],
            'a stamp the site did not sign' => ['email' => 'stale@example.org', 'guard_opened' => 'forged'],
            'no address' => ['email' => ''],
            'not an address' => ['email' => 'pas-une-adresse'],
            'no token' => ['email' => 'forged@example.org', '_token' => 'forged', '_csrf_token' => 'forged'],
        ] as $case => $fields) {
            $response = $this->buy($product, $fields);
            self::assertTrue($response->isRedirect('/boutiques'), $case.': back where the form was ('.$response->getStatusCode().')');
            $this->entityManager->clear();
            self::assertSame($before, $count(), $case.': no order');
        }
    }

    public function testAFormSentFasterThanItsDelayIsRefused(): void
    {
        // The harness asks no delay (base.guard.min_delay: 0): the form's own, as a site sets it.
        $request = Request::create('/commande-express/1', 'POST');
        $stack = self::getContainer()->get('request_stack');
        $stack->push($request);
        $form = self::getContainer()->get('form.factory')->createNamed('quick_order', \Base\Marketplace\Form\QuickOrderType::class, null, [
            'csrf_protection' => false,
            'guard' => ['action' => 'quick_order', 'email' => 'email', 'name' => '', 'min_delay' => 5, 'challenge' => false],
        ]);
        $form->submit(['email' => 'pressed@example.org', 'back' => '/', 'guard_website' => '', 'guard_opened' => self::getContainer()->get(FormGuard::class)->stamp(time() - 1)]);
        $stack->pop();

        self::assertFalse($form->isValid());
    }
}
