<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Attachment;
use Base\Marketplace\Security\MarketplaceVoter;
use Base\Marketplace\Service\Attachments;
use Base\Contract\UnitOfWork;
use Base\Database\UnitOfWorks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Downloading a file given with a quote request or an order line
 * (Entity\Attachment). The files are private: the back office reads them
 * (whoever may see the shop's screens), anybody else needs the signed link
 * Service\Attachments::url() hands out.
 */
class AttachmentController extends AbstractController
{
    #[Route('/marketplace/piece-jointe/{id}', name: 'marketplace_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function Download(Request $request, int $id, UnitOfWork $unitOfWork, Attachments $attachments, UriSigner $signer): Response
    {
        $signed = $request->query->has('_hash') && $signer->checkRequest($request);
        if (!$signed && !$this->isGranted(MarketplaceVoter::VIEW)) {
            throw $this->createAccessDeniedException('This file is the shop\'s.');
        }
        $attachment = UnitOfWorks::repositories($unitOfWork)->of(Attachment::class)->find($id);
        if (!$attachment instanceof Attachment || null === $attachments->file($attachment)) {
            throw $this->createNotFoundException('No such file.');
        }

        return $attachments->download($attachment);
    }
}
