<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Service\AgeGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The age gate's answer (@Marketplace/client/_age_gate.html.twig): yes keeps
 * the age confirmed in a cookie and goes back to the page; no leaves for
 * the page the application names (marketplace.age_gate: its home without
 * the restricted products, or elsewhere).
 */
class AgeGateController extends AbstractController
{
    #[Route('/age', name: 'marketplace_age_gate', methods: ['POST'])]
    public function Answer(Request $request, AgeGate $gate): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('marketplace_age_gate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $target = (string) $request->request->get('_target', '/');
        // Back to this site only.
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            $target = '/';
        }

        if ('yes' !== $request->request->get('answer')) {
            return new RedirectResponse((string) $request->request->get('_away', 'https://www.google.com'));
        }

        $response = new RedirectResponse($target);
        $response->headers->setCookie($gate->confirmation());

        return $response;
    }
}
