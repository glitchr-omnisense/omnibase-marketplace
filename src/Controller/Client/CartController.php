<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Payment\PaymentResult;
use Base\Marketplace\Service\Cart;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\Pricing;
use Base\Marketplace\Service\Shipping;
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

    #[Route('/panier', name: 'marketplace_cart')]
    public function Index(Pricing $pricing): Response
    {
        $carts = $this->cart->all();
        foreach ($carts as $cart) {
            $pricing->reprice($cart);
        }
        $this->entityManager->flush();

        return $this->render('@Marketplace/client/cart.html.twig', ['carts' => $carts]);
    }

    #[Route('/panier/ajouter/{id}', name: 'marketplace_cart_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function Add(Request $request, int $id): Response
    {
        $this->csrf($request, 'marketplace_cart');
        $product = $this->entityManager->getRepository(Product::class)->find($id);
        if (!$product instanceof Product) {
            throw $this->createNotFoundException('Unknown product.');
        }

        try {
            $this->cart->add($product, max(1, $request->request->getInt('quantity', 1)));
            $this->addFlash('success', $this->translator->trans('@marketplace.cart.added', ['{product}' => (string) $product]));
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage(), $e->getParameters()));
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('marketplace_cart'));
    }

    #[Route('/panier/{order}/ligne/{item}', name: 'marketplace_cart_update', methods: ['POST'], requirements: ['order' => '\d+', 'item' => '\d+'])]
    public function Update(Request $request, int $order, int $item): Response
    {
        $this->csrf($request, 'marketplace_cart');
        [$cart, $line] = $this->line($order, $item);

        try {
            $this->cart->update($cart, $line, $request->request->getInt('quantity', 0));
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage(), $e->getParameters()));
        }

        return $this->redirectToRoute('marketplace_cart');
    }

    #[Route('/panier/{order}/commander', name: 'marketplace_checkout', requirements: ['order' => '\d+'])]
    public function Checkout(Request $request, int $order, Pricing $pricing, Shipping $shipping): Response
    {
        $cart = $this->entityManager->getRepository(Order::class)->find($order);
        if (!$cart instanceof Order) {
            throw $this->createNotFoundException('Unknown cart.');
        }

        try {
            $this->cart->assertMine($cart);
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage()));
            return $this->redirectToRoute('marketplace_cart');
        }

        $methods = $this->checkout->methodsFor($cart);

        if ($request->isMethod('POST')) {
            $this->csrf($request, 'marketplace_checkout_'.$cart->getId());
            $method = $this->entityManager->getRepository(PaymentMethod::class)->find($request->request->getInt('method'));
            if (!$method instanceof PaymentMethod || !in_array($method, $methods, true)) {
                $this->addFlash('error', $this->translator->trans('@marketplace.checkout.error.method'));
                return $this->redirectToRoute('marketplace_checkout', ['order' => $cart->getId()]);
            }

            if ($shipping->needsShipping($cart)) {
                $address = (array) $request->request->all('address');
                $error = $shipping->apply($cart, $address, $request->request->getInt('shipping'));
                // Outside the shop's regions (Japan for a French cellar): the
                // cart does not go there; a quote does.
                if (Shipping::OUT_OF_ZONE === $error && $this->getParameter('marketplace.quotes.enabled')) {
                    $this->addFlash('info', $this->translator->trans('@marketplace.'.$error));

                    return $this->redirectToRoute('marketplace_quote_request', [
                        'country' => strtoupper((string) ($address['country'] ?? '')),
                        'product' => array_values(array_filter(array_map(fn ($item) => $item->getProduct()?->getId(), $cart->getItems()->toArray()))),
                    ]);
                }
                if ($error) {
                    $this->addFlash('error', $this->translator->trans('@marketplace.'.$error));

                    return $this->redirectToRoute('marketplace_checkout', ['order' => $cart->getId()]);
                }
            }
            $pricing->reprice($cart);
            $this->entityManager->flush();

            try {
                $result = $this->checkout->pay($cart, $method);
            } catch (CartException $e) {
                $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage(), $e->getParameters()));
                return $this->redirectToRoute('marketplace_cart');
            }

            return match ($result->status) {
                PaymentResult::REDIRECT => $this->redirect($result->redirectUrl),
                PaymentResult::REFUSED => $this->refused($cart, $result),
                default => $this->done($cart, $result),
            };
        }

        $pricing->reprice($cart);
        $this->entityManager->flush();

        return $this->render('@Marketplace/client/checkout.html.twig', [
            'order' => $cart,
            'methods' => $methods,
            'needs_shipping' => $shipping->needsShipping($cart),
            'shipping_options' => $shipping->optionsFor($cart),
            'address' => $shipping->addressOf($cart),
        ]);
    }

    private function done(Order $order, PaymentResult $result): Response
    {
        $this->addFlash('success', $this->translator->trans($result->isPaid() ? '@marketplace.checkout.paid' : '@marketplace.checkout.pending', ['{reference}' => (string) $order->getReference()]));

        return $this->redirectToRoute('marketplace_order', ['reference' => $order->getReference()]);
    }

    private function refused(Order $order, PaymentResult $result): Response
    {
        $this->addFlash('error', $this->translator->trans('@marketplace.'.($result->reason ?? 'checkout.error.refused'), $result->parameters));

        return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
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
