<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Service\Supply;
use Base\Marketplace\Supply\Model\SupplyResult;
use Base\Marketplace\Supply\SupplierRegistry;
use Base\Marketplace\Supply\SupplyException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The suppliers' side: the page a partner workshop reaches from its brief's
 * signed link - what to make, and a form to accept, say where it stands and
 * give the tracking -, and the webhook of the suppliers that have one.
 */
class SupplyController extends AbstractController
{
    /** What a workshop may say of a job. */
    private const WORKSHOP_STATES = [SupplyStatus::ACCEPTED, SupplyStatus::IN_PRODUCTION, SupplyStatus::SHIPPED, SupplyStatus::DELIVERED, SupplyStatus::FAILED];

    public function __construct(private readonly Supply $supply)
    {
    }

    #[Route('/marketplace/fabrication/{reference}', name: 'marketplace_supply_offline', methods: ['GET', 'POST'])]
    public function Offline(Request $request, UriSigner $signer, string $reference): Response
    {
        // The link is the key: no account on the workshop's side.
        if (!$signer->checkRequest($request)) {
            throw $this->createAccessDeniedException();
        }
        $job = $this->supply->find($reference);
        if (!$job || 'offline' !== $job->getSupplier()) {
            throw $this->createNotFoundException();
        }

        $saved = false;
        if ($request->isMethod('POST') && !$job->getStatus()->isFinal()) {
            $status = SupplyStatus::tryFrom((string) $request->request->get('status'));
            if (null !== $status && \in_array($status, self::WORKSHOP_STATES, true)) {
                $this->supply->apply(new SupplyResult(
                    'offline',
                    $job->getReference(),
                    $status,
                    trim((string) $request->request->get('tracking_number')) ?: null,
                    filter_var(trim((string) $request->request->get('tracking_url')), \FILTER_VALIDATE_URL) ?: null,
                    trim((string) $request->request->get('carrier')) ?: null,
                    trim((string) $request->request->get('message')) ?: null,
                    $job->getReference(),
                ));
                $saved = true;
            }
        }

        return $this->render('@Marketplace/client/supply_offline.html.twig', ['job' => $job, 'states' => self::WORKSHOP_STATES, 'saved' => $saved, 'action' => $request->getUri()], new Response(null, 200, ['X-Robots-Tag' => 'noindex, nofollow']));
    }

    #[Route('/marketplace/supply/{supplier}/webhook', name: 'marketplace_supply_webhook', methods: ['POST'])]
    public function Webhook(Request $request, SupplierRegistry $suppliers, string $supplier): JsonResponse
    {
        $service = $suppliers->get($supplier);
        if (!$service) {
            return new JsonResponse(['ignored' => true]);
        }
        try {
            $result = $service->notify($request->getContent(), $request->headers->all());
        } catch (SupplyException) {
            return new JsonResponse(['error' => 'Invalid webhook.'], 400);
        }
        $job = $result ? $this->supply->apply($result) : null;

        return new JsonResponse($job ? ['received' => $job->getReference(), 'status' => $job->getStatus()->value] : ['ignored' => true]);
    }
}
