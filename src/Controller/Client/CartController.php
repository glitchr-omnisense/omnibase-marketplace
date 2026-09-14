<?php

namespace Base\Market\Controller\Client;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Market\Entity\Order\OrderItem;
use Base\Market\Entity\Product;
use Base\Market\Payment\PaymentResult;
use Base\Market\Service\Cart;
use Base\Market\Service\CartException;
use Base\Market\Service\Checkout;
use Base\Market\Service\Pricing;
use Base\Market\Service\Shipping;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The carts and the checkout. Every change is a POST with a CSRF token; nothing needs JavaScript. */
#[IsGranted('ROLE_USER')]
class CartController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Cart $cart,
        private readonly Checkout $checkout,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/panier', name: 'market_cart')]
    public function Index(Pricing $pricing): Response
    {
        $carts = $this->cart->all();
        foreach ($carts as $cart) {
            $pricing->reprice($cart);
        }
        $this->entityManager->flush();

        return $this->render('@Market/client/cart.html.twig', ['carts' => $carts]);
    }

    #[Route('/panier/ajouter/{id}', name: 'market_cart_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function Add(Request $request, int $id): Response
    {
        $this->csrf($request, 'market_cart');
        $product = $this->entityManager->getRepository(Product::class)->find($id);
        if (!$product instanceof Product) {
            throw $this->createNotFoundException('Unknown product.');
        }

        try {
            $this->cart->add($product, max(1, $request->request->getInt('quantity', 1)));
            $this->addFlash('success', $this->translator->trans('@market.cart.added', ['{product}' => (string) $product]));
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@market.'.$e->getMessage(), $e->getParameters()));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('market_cart'));
    }

    #[Route('/panier/{order}/ligne/{item}', name: 'market_cart_update', methods: ['POST'], requirements: ['order' => '\d+', 'item' => '\d+'])]
    public function Update(Request $request, int $order, int $item): Response
    {
        $this->csrf($request, 'market_cart');
        [$cart, $line] = $this->line($order, $item);

        try {
            $this->cart->update($cart, $line, $request->request->getInt('quantity', 0));
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@market.'.$e->getMessage(), $e->getParameters()));
        }

        return $this->redirectToRoute('market_cart');
    }

    #[Route('/panier/{order}/commander', name: 'market_checkout', requirements: ['order' => '\d+'])]
    public function Checkout(Request $request, int $order, Pricing $pricing, Shipping $shipping): Response
    {
        $cart = $this->entityManager->getRepository(Order::class)->find($order);
        if (!$cart instanceof Order) {
            throw $this->createNotFoundException('Unknown cart.');
        }

        try {
            $this->cart->assertMine($cart);
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@market.'.$e->getMessage()));
            return $this->redirectToRoute('market_cart');
        }

        $methods = $this->checkout->methodsFor($cart);

        if ($request->isMethod('POST')) {
            $this->csrf($request, 'market_checkout_'.$cart->getId());
            $method = $this->entityManager->getRepository(PaymentMethod::class)->find($request->request->getInt('method'));
            if (!$method instanceof PaymentMethod || !in_array($method, $methods, true)) {
                $this->addFlash('error', $this->translator->trans('@market.checkout.error.method'));
                return $this->redirectToRoute('market_checkout', ['order' => $cart->getId()]);
            }

            if ($shipping->needsShipping($cart)) {
                $error = $shipping->apply($cart, (array) $request->request->all('address'), $request->request->getInt('shipping'));
                if ($error) {
                    $this->addFlash('error', $this->translator->trans('@market.'.$error));

                    return $this->redirectToRoute('market_checkout', ['order' => $cart->getId()]);
                }
            }
            $pricing->reprice($cart);
            $this->entityManager->flush();

            try {
                $result = $this->checkout->pay($cart, $method);
            } catch (CartException $e) {
                $this->addFlash('error', $this->translator->trans('@market.'.$e->getMessage(), $e->getParameters()));
                return $this->redirectToRoute('market_cart');
            }

            return match ($result->status) {
                PaymentResult::REDIRECT => $this->redirect($result->redirectUrl),
                PaymentResult::REFUSED => $this->refused($cart, $result),
                default => $this->done($cart, $result),
            };
        }

        $pricing->reprice($cart);
        $this->entityManager->flush();

        return $this->render('@Market/client/checkout.html.twig', [
            'order' => $cart,
            'methods' => $methods,
            'needs_shipping' => $shipping->needsShipping($cart),
            'shipping_options' => $shipping->optionsFor($cart),
            'address' => $shipping->addressOf($cart),
        ]);
    }

    private function done(Order $order, PaymentResult $result): Response
    {
        $this->addFlash('success', $this->translator->trans($result->isPaid() ? '@market.checkout.paid' : '@market.checkout.pending', ['{reference}' => (string) $order->getReference()]));

        return $this->redirectToRoute('market_order', ['reference' => $order->getReference()]);
    }

    private function refused(Order $order, PaymentResult $result): Response
    {
        $this->addFlash('error', $this->translator->trans('@market.'.($result->reason ?? 'checkout.error.refused'), $result->parameters));

        return $this->redirectToRoute('market_checkout', ['order' => $order->getId()]);
    }

    /** @return array{0: Order, 1: OrderItem} */
    private function line(int $order, int $item): array
    {
        $cart = $this->entityManager->getRepository(Order::class)->find($order);
        $line = $this->entityManager->getRepository(OrderItem::class)->find($item);
        if (!$cart instanceof Order || !$line instanceof OrderItem || $line->getOrder() !== $cart) {
            throw $this->createNotFoundException('Unknown cart line.');
        }

        return [$cart, $line];
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }
}
