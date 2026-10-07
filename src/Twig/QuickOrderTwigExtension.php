<?php

namespace Base\Marketplace\Twig;

use Base\Entity\User;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Form\QuickOrderType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The quick order's form (Form\QuickOrderType), for a product, wherever a
 * template shows one:
 *
 *   {% set form = marketplace_quick_order_form(product) %}
 *   {% set form = marketplace_quick_order_form(product, '/carte') %}   where a refusal brings the buyer back
 *
 * @Marketplace/client/_quick_order.html.twig prints it.
 */
final class QuickOrderTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requests,
        private readonly ?Security $security = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('marketplace_quick_order_form', $this->view(...))];
    }

    public function view(Product $product, ?string $back = null): FormView
    {
        return $this->form($product, $back)->createView();
    }

    /** The form for that product: posted to marketplace_quick_order, asking an address of a visitor signed out. */
    public function form(Product $product, ?string $back = null): FormInterface
    {
        return $this->forms->createNamed(QuickOrderType::NAME, QuickOrderType::class, null, [
            'action' => $this->router->generate('marketplace_quick_order', ['id' => $product->getId()]),
            'ask_email' => !$this->security?->getUser() instanceof User,
            'back' => $back ?? $this->requests->getCurrentRequest()?->getRequestUri(),
        ]);
    }
}
