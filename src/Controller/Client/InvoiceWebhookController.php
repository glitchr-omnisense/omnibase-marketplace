<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Invoice\Transmission\InvoiceTransmission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An approved platform's callback about an invoice it carries
 * (glitchr/omnibill, omnibill.gateways.<gateway>: a gateway that takes
 * webhooks): its signature checked by the gateway, the invoice's lifecycle
 * status updated. Without glitchr/omnibill there is no transmission: 404.
 */
class InvoiceWebhookController extends AbstractController
{
    public function __construct(private readonly ?InvoiceTransmission $transmission = null)
    {
    }

    #[Route('/marketplace/omnibill/{gateway}/webhook', name: 'marketplace_invoice_webhook', methods: ['POST'], requirements: ['gateway' => '[a-z0-9_-]+'])]
    public function Webhook(Request $request, string $gateway): JsonResponse
    {
        if (null === $this->transmission || !$this->transmission->supportsNotify($gateway)) {
            return new JsonResponse(['error' => 'No such gateway.'], 404);
        }
        $invoice = $this->transmission->notify($gateway, $request->getContent(), $request->headers->all());
        if (false === $invoice) {
            return new JsonResponse(['error' => 'Invalid signature.'], 400);
        }

        return new JsonResponse(null === $invoice ? ['ignored' => true] : ['invoice' => $invoice->getNumber(), 'status' => $this->transmission->flowOf($invoice)?->getStatus()]);
    }
}
