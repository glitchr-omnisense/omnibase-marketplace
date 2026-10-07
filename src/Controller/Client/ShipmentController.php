<?php

namespace Base\Marketplace\Controller\Client;

use Base\Admin\Context\AdminContext;
use Base\Admin\EventSubscriber\NestHeaderSubscriber;
use Base\Admin\Menu\MenuBuilder;
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
 *
 * A screen of the back office: under /admin, in its layout with its menus,
 * and nestable (X-Transparent-Nest) like every admin page - not a page of
 * the shop. Its first address, /commandes/a-expedier, leads there.
 */
#[IsGranted('ROLE_ADMIN')]
class ShipmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Shipping $shipping,
        private readonly TranslatorInterface $translator,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
    ) {
    }

    #[Route('/admin/commandes/a-expedier', name: 'marketplace_shipping_queue', methods: ['GET'])]
    public function Queue(): Response
    {
        // Same seeding as omnibase/admin's own non-CRUD pages (TrashController):
        // without it the layout renders an empty sidebar and no account menu.
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        $response = $this->render('@Marketplace/admin/shipping_queue.html.twig', [
            'admin_context' => $this->adminContext,
            'orders' => $this->shipping->queue(),
        ]);
        // Set here rather than left to the subscriber, which knows the
        // admin's own routes (admin_*) and not this one.
        $response->headers->set(NestHeaderSubscriber::HEADER, 'overlay');

        return $response;
    }

    /** The queue's address before it moved into the back office: bookmarks and old links still land. */
    #[Route('/commandes/a-expedier', name: 'marketplace_shipping_queue_legacy', methods: ['GET'], priority: 1)]
    public function LegacyQueue(): Response
    {
        return $this->redirectToRoute('marketplace_shipping_queue', [], Response::HTTP_MOVED_PERMANENTLY);
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
