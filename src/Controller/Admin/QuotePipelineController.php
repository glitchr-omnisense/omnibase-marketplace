<?php

namespace Base\Marketplace\Controller\Admin;

use Base\Admin\Context\AdminContext;
use Base\Admin\Menu\MenuBuilder;
use Base\Admin\Router\AdminUrlGenerator;
use Base\Marketplace\Controller\Admin\Crud\QuoteCrudController;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Repository\QuoteRepository;
use Base\Marketplace\Security\MarketplaceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The quotes as a pipeline, in the back office (it opens in the nest, like
 * every admin page): one column per status - asked, being written, sent,
 * accepted, paid, declined -, each card its client, company, terms, total
 * and how long it has waited, a click away from its form.
 */
#[IsGranted(MarketplaceVoter::VIEW)]
class QuotePipelineController extends AbstractController
{
    public function __construct(
        private readonly QuoteRepository $quotes,
        private readonly AdminContext $adminContext,
        private readonly MenuBuilder $menuBuilder,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/marketplace/quotes', name: 'marketplace_admin_quotes', methods: ['GET'])]
    public function index(): Response
    {
        if ([] === $this->adminContext->getMainMenu()) {
            $this->adminContext->setMainMenu($this->menuBuilder->buildDefault());
        }
        if ([] === $this->adminContext->getUserMenu()) {
            $this->adminContext->setUserMenu($this->menuBuilder->buildUserMenuDefault($this->getUser()));
        }

        $columns = $this->quotes->pipeline();
        $links = [];
        foreach ($columns as $quotes) {
            foreach ($quotes as $quote) {
                $links[$quote->getId()] = $this->adminUrlGenerator->setController(QuoteCrudController::class)->setAction('edit')->setEntityId($quote->getId())->generateUrl();
            }
        }

        return $this->render('@Marketplace/admin/quote_pipeline.html.twig', [
            'admin_context' => $this->adminContext,
            'columns' => $columns,
            'statuses' => QuoteStatus::pipeline(),
            'links' => $links,
            'new' => $this->adminUrlGenerator->setController(QuoteCrudController::class)->setAction('new')->generateUrl(),
        ]);
    }
}
