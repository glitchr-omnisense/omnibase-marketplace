<?php

namespace App\Controller;

use App\Entity\User;
use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Product;
use Base\Market\Entity\Store;
use Base\Market\Event\OrderPaidEvent;
use Base\Market\Payment\PaymentGatewayRegistry;
use Base\Market\Service\Cart;
use Base\Market\Service\Checkout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The demonstrator's one page: it seeds a small shop, signs a visitor in,
 * fills a cart and pays two orders - then hands over to the shop itself.
 *
 * Everything here is demo scaffolding. A real application seeds stores from
 * a fixture or the admin, and members shop through the bundle's pages;
 * nothing in this file is a pattern to copy.
 */
class DemoController extends AbstractController
{
    private array $paidEvents = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly Cart $cart,
        private readonly Checkout $checkout,
        private readonly PaymentGatewayRegistry $gateways,
        EventDispatcherInterface $dispatcher,
    ) {
        // What an application does on a sale: deliver, or tell the staff.
        $dispatcher->addListener(OrderPaidEvent::class, function (OrderPaidEvent $event) {
            $this->paidEvents[] = (string) $event->order->getReference();
        });
    }

    #[Route('/', name: 'demo_index')]
    public function index(): Response
    {
        $sections = [];
        $demo = function (string $title, string $about, callable $fn) use (&$sections) {
            try {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => true, 'output' => $fn()];
            } catch (\Throwable $e) {
                $sections[] = ['title' => $title, 'about' => $about, 'ok' => false, 'output' => $e::class.': '.$e->getMessage()];
            }
        };

        $demo('Seed', 'A member, a store in euros with three products, and two payment methods - created once, then found again on every reload.', fn () => $this->seed());

        $demo('Payment gateways', 'Every service implementing PaymentGatewayInterface is collected; a payment method names one. "manual" ships with the bundle, "demo" is this application\'s.', function () {
            $lines = ['gateways: '.implode(', ', $this->gateways->names())];
            foreach ($this->entityManager->getRepository(PaymentMethod::class)->findAll() as $method) {
                $lines[] = sprintf('  %-10s -> %s', $method->getSlug(), $method->getGatewayFactory());
            }

            return implode("\n", $lines);
        });

        $demo('A cart', 'One cart per store and member. A product may sell fewer at once than the shop allows: Product::getMaxQuantity(), here cart_max_quantity: 5.', function () {
            $store = $this->store();
            $mug = $this->product('mug');
            $cart = $this->cart->add($mug, 2);
            $this->cart->add($this->product('poster'), 9);

            $lines = [];
            foreach ($cart->getItems() as $line) {
                $lines[] = sprintf('%d x %-18s %s', $line->getQuantity(), $line->getProduct()->getTitle(), $this->money($line->getSalePrice(), $cart->getCurrency()));
            }
            $lines[] = sprintf('%-22s %s', 'total', $this->money($cart->getNetPrice(), $cart->getCurrency()));
            $lines[] = "\nlines in the member's carts: ".$this->cart->count().' ('.$store->getTitle().')';

            return implode("\n", $lines);
        });

        $demo('Checkout, paid at once', 'Checkout turns the cart into an order with a reference, hands a transaction to the gateway, and on "paid" confirms it, decrements the stock and dispatches OrderPaidEvent.', function () {
            $store = $this->store();
            $cart = $this->cart->of($store, true);
            if ($cart->getItems()->isEmpty()) {
                $this->cart->add($this->product('mug'));
            }
            $poster = $this->product('poster');
            $stockBefore = $poster->getStock();

            $result = $this->checkout->pay($cart, $this->method('demo'));

            return sprintf("result:    %s\nreference: %s\nstate:     %s\npaid at:   %s\nposter stock: %s -> %s\nOrderPaidEvent for: %s",
                $result->status, $cart->getReference(), $cart->getState(),
                $cart->getPaidAt()?->format('Y-m-d H:i'), $stockBefore ?? 'unlimited', $poster->getStock() ?? 'unlimited',
                implode(', ', $this->paidEvents) ?: 'nobody');
        });

        $demo('Checkout, paid later', 'A bank transfer answers "pending": the order waits with the instructions from market.gateways.virement, and nothing is dispatched yet.', function () {
            $this->cart->add($this->product('badges'));
            $cart = $this->cart->of($this->store());
            $result = $this->checkout->pay($cart, $this->method('virement'));
            $details = $cart->getTransactions()->last() ? $cart->getTransactions()->last()->getDetails() : [];

            return sprintf("result:    %s\nreference: %s\nstate:     %s\nto pay:    %s",
                $result->status, $cart->getReference(), $cart->getState(), $details['instructions'] ?? '-');
        });

        $demo('The member\'s orders', 'What /commandes lists.', function () {
            $orders = $this->entityManager->getRepository(Order::class)->findBy(['customer' => $this->getUser()], ['id' => 'DESC'], 6);

            return implode("\n", array_map(fn (Order $o) => sprintf('%-14s %-10s %s', $o->getReference() ?: '(cart)', $o->getState(), $this->money($o->getNetPrice(), $o->getCurrency())), $orders)) ?: 'none';
        });

        return $this->render('demo/index.html.twig', ['sections' => $sections]);
    }

    private function seed(): string
    {
        $created = [];
        $users = $this->entityManager->getRepository(User::class);
        if (!$me = $users->findOneBy(['username' => 'Alice'])) {
            $me = $this->member('Alice', 'alice@example.org', ['ROLE_ADMIN']);
            $created[] = 'member Alice';
        }

        if (!$store = $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => 'boutique'])) {
            $store = new Store();
            $store->setTitle('Boutique');
            $store->setSlug('boutique');
            $store->setExcerpt('A small shop in euros.');
            $store->setCurrency('EUR');
            $store->setOpen(true);
            $this->entityManager->persist($store);
            $created[] = 'store Boutique';
        }

        foreach ([['mug', 'Mug', 1290, null], ['poster', 'Poster', 990, 20], ['badges', 'Three badges', 490, 100]] as [$slug, $title, $price, $stock]) {
            if (!$this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug])) {
                $product = new Product(null, $store, $price, 'EUR');
                $product->setTitle($title);
                $product->setSlug($slug);
                $product->setExcerpt($title.' from the demo shop.');
                $product->setStock($stock);
                $this->entityManager->persist($product);
                $created[] = 'product '.$title;
            }
        }

        foreach ([['demo', 'Pay now (demo gateway)', 'demo'], ['virement', 'Bank transfer', 'manual']] as [$slug, $label, $gateway]) {
            if (!$this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['slug' => $slug])) {
                $method = new PaymentMethod();
                $method->setSlug($slug);
                $method->setLabel($label);
                $method->setGatewayFactory($gateway);
                $this->entityManager->persist($method);
                $created[] = 'payment method '.$slug;
            }
        }

        $this->entityManager->flush();
        $this->security->login($me, 'security.authenticator.form_login.main');

        return ($created ? "created:\n  ".implode("\n  ", $created) : 'already seeded')."\nsigned in as ".$me->getUsername();
    }

    private function member(string $username, string $email, array $roles): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPlainPassword('demo');
        $user->setRoles($roles);
        $user->verify();
        $this->entityManager->persist($user);

        return $user;
    }

    private function store(): Store
    {
        return $this->entityManager->getRepository(Store::class)->findOneBy(['slug' => 'boutique']);
    }

    private function product(string $slug): Product
    {
        return $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
    }

    private function method(string $slug): PaymentMethod
    {
        return $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['slug' => $slug]);
    }

    private function money(int $amount, string $currency): string
    {
        return number_format($amount / 100, 2, ',', ' ').' '.$currency;
    }
}
