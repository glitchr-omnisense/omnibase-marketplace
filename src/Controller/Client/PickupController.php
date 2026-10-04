<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Repository\Order\PickupRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Following a hand-over (Entity\Order\Pickup) through its link, without an
 * account: the token is the key. The orders made together share it and show
 * on the same page.
 */
class PickupController extends AbstractController
{
    #[Route('/remise/{token}', name: 'marketplace_pickup', requirements: ['token' => '[A-Za-z0-9_\-]{32,43}'], methods: ['GET'])]
    public function Track(string $token, PickupRepository $pickups): Response
    {
        $found = $pickups->byToken($token);
        if (!$found) {
            throw $this->createNotFoundException('No such hand-over.');
        }

        $response = $this->render('@Marketplace/client/pickup.html.twig', ['pickups' => $found]);
        // A private page behind a secret link: not for an index, nor a shared cache.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();

        return $response;
    }
}
