<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Service\CompanyRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the forms ask as a SIRET is typed: the company it names, to fill
 * its name and show whether it trades - from the State's register
 * (CompanyRegistry, through Omnistate), the answer kept a day.
 */
class CompanyController extends AbstractController
{
    #[Route('/api/company/{number}', name: 'marketplace_company_lookup', requirements: ['number' => '[0-9 ]{9,20}'], methods: ['GET'])]
    public function lookup(string $number, CompanyRegistry $registry): JsonResponse
    {
        $result = $registry->lookup($number);

        return $this->json([
            'status' => $result['status'],
            'company' => $result['company']?->toArray(),
        ], CompanyRegistry::UNAVAILABLE === $result['status'] ? 503 : 200, ['Cache-Control' => 'private, max-age=3600']);
    }
}
