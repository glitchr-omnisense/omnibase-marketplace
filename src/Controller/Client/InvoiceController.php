<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Security\InvoiceVoter;
use Base\Marketplace\Service\Invoices;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An invoice, as its PDF (its Factur-X XML attached): for its buyer signed
 * in, the back office (InvoiceVoter), or whoever holds its signed link
 * (Invoices::signedUrl(), the one its e-mail carries).
 */
class InvoiceController extends AbstractController
{
    public function __construct(private readonly Invoices $invoices)
    {
    }

    #[Route('/factures/{number}', name: 'marketplace_invoice', requirements: ['number' => '[A-Za-z0-9\-_]+'], methods: ['GET'])]
    public function Download(Request $request, string $number, UriSigner $signer): Response
    {
        $invoice = $this->invoices->repository()->findOneBy(['number' => $number]) ?? throw $this->createNotFoundException();
        if (!$signer->checkRequest($request) && !$this->isGranted(InvoiceVoter::VIEW, $invoice)) {
            throw $this->createAccessDeniedException();
        }

        return new Response($this->invoices->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition($request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $this->invoices->filename($invoice)),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
