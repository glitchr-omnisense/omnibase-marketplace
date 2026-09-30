<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Service\Shipping;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The staff side of posting goods: the orders waiting to leave, each with
 * its address, and the two steps - shipped (with the tracking number) and
 * delivered. The member follows both on their order page.
 */
#[IsGranted('ROLE_ADMIN')]
class ShipmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Shipping $shipping,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/commandes/a-expedier', name: 'marketplace_shipping_queue', priority: 1)]
    public function Queue(): Response
    {
        return $this->render('@Marketplace/client/shipping_queue.html.twig', ['orders' => $this->shipping->queue()]);
    }

    #[Route('/commandes/{reference}/expedier', name: 'marketplace_order_ship', methods: ['POST'], requirements: ['reference' => '[A-Za-z0-9\-]+'])]
    public function Ship(Request $request, string $reference): Response
    {
        $order = $this->order($request, $reference);
        $number = trim((string) $request->request->get('tracking'));
        if ('' === $number) {
            $this->addFlash('error', $this->translator->trans('@marketplace.shipping.error.tracking'));
        } elseif (!$order->isConfirmed()) {
            $this->addFlash('error', $this->translator->trans('@marketplace.shipping.error.state'));
        } else {
            $this->shipping->ship($order, $number);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('@marketplace.shipping.shipped', ['{reference}' => $order->getReference()]));
        }

        return $this->redirectToRoute('marketplace_shipping_queue');
    }

    #[Route('/commandes/{reference}/livree', name: 'marketplace_order_deliver', methods: ['POST'], requirements: ['reference' => '[A-Za-z0-9\-]+'])]
    public function Deliver(Request $request, string $reference): Response
    {
        $order = $this->order($request, $reference);
        $order->markAsCompleted();
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('@marketplace.shipping.delivered', ['{reference}' => $order->getReference()]));

        return $this->redirectToRoute('marketplace_shipping_queue');
    }

    private function order(Request $request, string $reference): Order
    {
        if (!$this->isCsrfTokenValid('marketplace_ship', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $reference]);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException('Unknown order.');
        }

        return $order;
    }
}
