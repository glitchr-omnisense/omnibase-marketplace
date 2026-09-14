<?php

namespace Base\Market\Controller\Client;

use Base\Market\Entity\Order;
use Base\Market\Entity\Sales\Discount\Coupon;
use Base\Market\Service\Cart;
use Base\Market\Service\CartException;
use Base\Market\Service\Pricing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A coupon code entered in the cart, or taken back out. */
#[IsGranted('ROLE_USER')]
class CouponController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Cart $cart,
        private readonly Pricing $pricing,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/panier/{order}/coupon', name: 'market_cart_coupon', methods: ['POST'], requirements: ['order' => '\d+'])]
    public function Apply(Request $request, int $order): Response
    {
        $cart = $this->mine($request, $order);
        try {
            $coupon = $this->pricing->applyCoupon($cart, (string) $request->request->get('code'), $this->getUser());
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('@market.coupon.applied', ['{code}' => $coupon->getCode()]));
        } catch (CartException $e) {
            $this->addFlash('error', $this->translator->trans('@market.'.$e->getMessage(), $e->getParameters()));
        }

        return $this->redirectToRoute('market_cart');
    }

    #[Route('/panier/{order}/coupon/{coupon}/retirer', name: 'market_cart_coupon_remove', methods: ['POST'], requirements: ['order' => '\d+', 'coupon' => '\d+'])]
    public function Remove(Request $request, int $order, int $coupon): Response
    {
        $cart = $this->mine($request, $order);
        $found = $this->entityManager->getRepository(Coupon::class)->find($coupon);
        if ($found instanceof Coupon) {
            $this->pricing->removeCoupon($cart, $found);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('market_cart');
    }

    private function mine(Request $request, int $order): Order
    {
        if (!$this->isCsrfTokenValid('market_cart', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $cart = $this->entityManager->getRepository(Order::class)->find($order);
        if (!$cart instanceof Order) {
            throw $this->createNotFoundException('Unknown cart.');
        }
        try {
            $this->cart->assertMine($cart);
        } catch (CartException) {
            throw $this->createNotFoundException('Unknown cart.');
        }

        return $cart;
    }
}
