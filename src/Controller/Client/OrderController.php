<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Enum\OrderState;
use Base\Service\PaginatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The member's orders - carts excluded - and one order's receipt. */
#[IsGranted('ROLE_USER')]
class OrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
        #[Autowire('%marketplace.orders_per_page%')] private readonly int $perPage = 20,
    ) {
    }

    #[Route('/commandes', name: 'marketplace_orders')]
    public function Index(Request $request): Response
    {
        $query = $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
            ->andWhere('o.customer = :me')->setParameter('me', $this->getUser())
            ->andWhere('o.state NOT IN (:carts)')->setParameter('carts', [OrderState::CART, OrderState::ABANDON])
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery();

        return $this->render('@Marketplace/client/orders.html.twig', [
            'orders' => $this->paginator->paginate($query, max(1, $request->query->getInt('page', 1)), $this->perPage),
        ]);
    }

    #[Route('/commandes/{reference}', name: 'marketplace_order', requirements: ['reference' => '[A-Za-z0-9\-]+'])]
    public function Show(string $reference): Response
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $reference]);
        if (!$order instanceof Order || (!$order->isCustomer($this->getUser()) && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createNotFoundException('Unknown order.');
        }

        return $this->render('@Marketplace/client/order.html.twig', ['order' => $order]);
    }
}
