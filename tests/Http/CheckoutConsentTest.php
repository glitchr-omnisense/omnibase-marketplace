<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Consent;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductKind;
use Base\Marketplace\Enum\Withdrawal;
use Base\Marketplace\Model\ConsentStatement;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Consents;
use Base\Marketplace\Service\QuickOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * The statements a buyer makes before paying, as a browser posts the checkout's form
 * (CartController::Checkout, Form\CheckoutConsentType, Service\Consents): the terms of sale's
 * box whenever the host names its terms, the immediate supply's box only for a basket holding
 * digital content, the early start's for a service - none ticked beforehand, each one required;
 * what was stated recorded on the order word for word (Entity\Order\Consent), and confirmed in
 * the e-mail its buyer receives once it is paid (OrderConfirmationListener). Paid with the trial
 * gateway ("dev"); the products are packs of credits granting nothing, so that no address is
 * asked.
 */
final class CheckoutConsentTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    /** @var list<int> */
    private array $methods = [];
    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ([] !== $this->entityManager->getRepository(PaymentMethod::class)->findAll()) {
            self::markTestSkipped('The host has payment methods of its own: which one the checkout preselects would be theirs to say.');
        }
        $method = new PaymentMethod();
        $method->setSlug('dev-'.bin2hex(random_bytes(3)));
        $method->setLabel('dev');
        $method->setGatewayFactory('dev');
        $this->entityManager->persist($method);
        $this->entityManager->flush();
        $this->methods[] = $method->getId();
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

    public function testADigitalBasketAsksForTheTermsAndTheImmediateSupplyAndRecordsBoth(): void
    {
        $this->terms('2026-11-14');
        $cart = $this->cart(Withdrawal::DIGITAL_CONTENT);

        $page = $this->request('GET', $this->checkout($cart));
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getContent();
        foreach (['consent[terms]', 'consent[immediate_supply]'] as $box) {
            self::assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="'.preg_quote($box, '/').'"[^>]*>/', $html, $box.' is shown');
            preg_match('/<input[^>]*name="'.preg_quote($box, '/').'"[^>]*>/', $html, $input);
            self::assertStringNotContainsString('checked', $input[0], $box.' is not ticked beforehand');
            self::assertStringContainsString('required', $input[0], $box.' is required');
        }
        self::assertStringContainsString($this->text('immediate_supply'), html_entity_decode($html, \ENT_QUOTES), 'the very text that is recorded');
        self::assertStringContainsString('href="'.$this->termsUrl().'"', $html, 'the terms of sale are linked');

        // No box ticked, then the terms alone: refused, nothing paid, nothing recorded.
        foreach ([[], ['terms' => '1']] as $ticked) {
            $refused = $this->pay($cart, $html, $ticked);
            self::assertSame(422, $refused->getStatusCode());
            self::assertStringContainsString($this->text('error.immediate_supply'), html_entity_decode((string) $refused->getContent(), \ENT_QUOTES));
            $this->entityManager->clear();
            self::assertNull($this->entityManager->find(Order::class, $cart->getId())->getPaidAt());
            self::assertSame([], $this->consents($cart));
        }

        $paid = $this->pay($cart, $html, ['terms' => '1', 'immediate_supply' => '1']);
        self::assertSame(302, $paid->getStatusCode(), substr((string) $paid->getContent(), 0, 2000));
        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $cart->getId());
        self::assertNotNull($order->getPaidAt(), 'paid once both are stated');

        $consents = $this->consents($order);
        self::assertSame(['terms', 'immediate_supply'], array_map(static fn (Consent $c) => $c->getStatement(), $consents));
        foreach ($consents as $consent) {
            self::assertSame($this->text($consent->getStatement()), $consent->getText());
            self::assertSame(hash('sha256', $consent->getText()), $consent->getHash());
            self::assertSame([Consent::CHECKOUT, '2026-11-14'], [$consent->getChannel(), $consent->getTermsVersion()]);
        }
        self::assertSame($this->termsUrl(), $consents[0]->getUrl());

        // The durable medium: the confirmation e-mail carries the statements and the terms' version.
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), (string) $order->getReference())));
        self::assertCount(1, $mails, 'one confirmation');
        self::assertSame($order->getCustomer()->getEmail(), $mails[0]->getTo()[0]->getAddress());
        $body = html_entity_decode((string) $mails[0]->getHtmlBody(), \ENT_QUOTES);
        self::assertStringContainsString($this->text('immediate_supply'), $body);
        self::assertStringContainsString($this->text('terms'), $body);
        self::assertStringContainsString('2026-11-14', $body);
        self::assertStringContainsString($this->termsUrl(), $body);
    }

    public function testABasketWithoutDigitalContentAsksForTheTermsOnly(): void
    {
        $this->terms();
        $cart = $this->cart(Withdrawal::NONE);
        $html = (string) $this->request('GET', $this->checkout($cart))->getContent();
        self::assertStringContainsString('name="consent[terms]"', $html);
        self::assertStringNotContainsString('consent[immediate_supply]', $html);
        self::assertStringNotContainsString('consent[early_service]', $html);

        self::assertSame(422, $this->pay($cart, $html, [])->getStatusCode(), 'the terms are required');
        self::assertSame(302, $this->pay($cart, $html, ['terms' => '1'])->getStatusCode());
        $this->entityManager->clear();
        self::assertSame(['terms'], array_map(static fn (Consent $c) => $c->getStatement(), $this->consents($cart)));
    }

    public function testAServiceAsksForTheEarlyStart(): void
    {
        $this->terms();
        $cart = $this->cart(Withdrawal::SERVICE);
        $html = (string) $this->request('GET', $this->checkout($cart))->getContent();
        self::assertStringContainsString('name="consent[early_service]"', $html);
        self::assertStringNotContainsString('consent[immediate_supply]', $html);

        $refused = $this->pay($cart, $html, ['terms' => '1']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString($this->text('error.early_service'), html_entity_decode((string) $refused->getContent(), \ENT_QUOTES));
        self::assertSame(302, $this->pay($cart, $html, ['terms' => '1', 'early_service' => '1'])->getStatusCode());
        $this->entityManager->clear();
        self::assertSame(['terms', 'early_service'], array_map(static fn (Consent $c) => $c->getStatement(), $this->consents($cart)));
    }

    public function testTextsChangedSinceThePageAreShownAgain(): void
    {
        $this->terms();
        $cart = $this->cart(Withdrawal::NONE);
        $html = (string) $this->request('GET', $this->checkout($cart))->getContent();

        $stale = $this->pay($cart, $html, ['terms' => '1', 'version' => str_repeat('0', 64)]);
        self::assertSame(422, $stale->getStatusCode());
        self::assertStringContainsString($this->text('error.changed'), html_entity_decode((string) $stale->getContent(), \ENT_QUOTES));
    }

    public function testAHostThatNamesNoTermsAndMarksNothingKeepsItsCheckout(): void
    {
        // The bundle's defaults: no terms route, products not marked.
        $cart = $this->cart(Withdrawal::NONE);
        $html = (string) $this->request('GET', $this->checkout($cart))->getContent();
        self::assertStringNotContainsString('consent[', $html);

        self::assertSame(302, $this->pay($cart, $html, [])->getStatusCode());
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Order::class, $cart->getId())->getPaidAt());
        self::assertSame([], $this->consents($cart));
        self::assertSame([], array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), (string) $cart->getReference()))), 'no statement, no confirmation of this bundle\'s');
    }

    public function testAQuickOrderSendsDigitalContentToTheBasket(): void
    {
        $product = $this->goods('quick', 1900);
        $product->setKind(ProductKind::CREDIT_PACK);
        $product->setWithdrawal(Withdrawal::DIGITAL_CONTENT);
        $this->entityManager->flush();
        try {
            self::getContainer()->get(QuickOrder::class)->buy($product, 'quick-'.bin2hex(random_bytes(3)).'@example.org');
            self::fail('a quick order asks for no statement: not for digital content');
        } catch (CartException $e) {
            self::assertSame('quick.error.statements', $e->getMessage());
        }
    }

    /** The host names its terms (marketplace.consent.terms_route): any route of the shop stands for them here. */
    private function terms(?string $version = null): void
    {
        $container = self::getContainer();
        $container->set(Consents::class, new Consents($container->get(EntityManagerInterface::class), $container->get(TranslatorInterface::class), $container->get(RouterInterface::class), 'marketplace_cart', [], $version));
    }

    private function termsUrl(): string
    {
        return self::getContainer()->get(RouterInterface::class)->generate('marketplace_cart', [], RouterInterface::ABSOLUTE_URL);
    }

    private function text(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans('@marketplace.consent.'.$key);
    }

    /** A signed-in buyer's cart of one pack of credits marked as $withdrawal. */
    private function cart(Withdrawal $withdrawal): Order
    {
        $buyer = $this->user('buyer');
        $buyer->setRoles(['ROLE_USER']);
        if (method_exists($buyer, 'verify')) {
            $buyer->verify();
        }
        $product = $this->goods('pack', 1900);
        $product->setKind(ProductKind::CREDIT_PACK);
        $product->setWithdrawal($withdrawal);
        $cart = new Order($this->store());
        $cart->setCustomer($buyer);
        $cart->setRegion($this->region);
        $item = new OrderItem($product, 1);
        $cart->addItem($item);
        $this->entityManager->persist($cart);
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        self::assertSame($withdrawal, $product->getWithdrawal());
        $this->signIn($buyer);

        return $cart;
    }

    private function checkout(Order $cart): string
    {
        return '/panier/'.$cart->getId().'/commander';
    }

    /**
     * The checkout's form posted as the page gave it, with the boxes $ticked.
     *
     * @param array<string, string> $ticked statement id => '1', or 'version' to post another
     */
    private function pay(Order $cart, string $html, array $ticked): Response
    {
        preg_match('/name="_token" value="([^"]+)"/', $html, $token);
        preg_match('/name="method" value="(\d+)"/', $html, $method);
        preg_match('/name="consent\[version\]"[^>]*value="([0-9a-f]*)"|value="([0-9a-f]*)"[^>]*name="consent\[version\]"/', $html, $version);
        $fields = ['_token' => $token[1] ?? '', 'method' => $method[1] ?? ''];
        $consent = $ticked + array_filter(['version' => ($version[1] ?? '') ?: ($version[2] ?? '')]);
        if (str_contains($html, 'consent[')) {
            $fields['consent'] = $consent;
        }

        return $this->request('POST', $this->checkout($cart), $fields);
    }

    /** @return list<Consent> */
    private function consents(Order $order): array
    {
        return $this->entityManager->getRepository(Consent::class)->findBy(['order' => $order->getId()], ['id' => 'ASC']);
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $method, string $uri, array $parameters = []): Response
    {
        $response = self::$kernel->handle(Request::create('https://localhost'.$uri, $method, $parameters, $this->cookies, [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ORIGIN' => 'https://localhost']));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** As KernelBrowser::loginUser() does: the token in a session, its cookie on the visitor. */
    private function signIn(\Base\Entity\User $user): void
    {
        try {
            $context = self::getContainer()->get('security.firewall.map.config.main')->getContext() ?: 'main';
        } catch (\Throwable) {
            $context = 'main';
        }
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security_'.$context, serialize(new TestBrowserToken($user->getRoles(), $user, $context)));
        $session->save();
        $this->cookies = [$session->getName() => $session->getId()];
    }
}
