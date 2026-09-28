<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Market\Entity\Order\Method\PaymentMethod;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\ImageField;
use Base\Market\Payment\PaymentGatewayRegistry;

/**
 * Admin CRUD for the payment methods offered at checkout. The gateway is the
 * name() of a service tagged market.payment_gateway ("manual" ships with the
 * bundle; an application adds its own).
 */
class PaymentMethodCrudController extends AbstractCrudController
{
    public function __construct(private readonly PaymentGatewayRegistry $gateways)
    {
    }

    public static function getEntityFqcn(): string
    {
        return PaymentMethod::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-credit-card';
    }

    /** A method orders were paid with stays: they point to it. */
    public function isDeletable(object $entity): bool
    {
        return 0 === \count($entity->getOrders());
    }

    /** Whether its gateway is installed, and how many of its settings are filled (market.gateways.<slug>). */
    public function configureRecordNote(object $entity): ?string
    {
        $settings = \count(array_filter($entity->getGatewayParameters()));

        return sprintf(
            'Gateway "%s" %s · %d setting(s) under market.gateways.%s',
            $entity->getGatewayFactory(),
            null !== $this->gateways->for($entity) ? 'installed' : 'not installed',
            $settings,
            str_replace('-', '_', (string) $entity->getSlug()),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield TextField::new('gatewayFactory', 'Gateway')->setColumns(6);
        yield IntegerField::new('refundFee')->setColumns(3)->hideOnIndex();
        yield ImageField::new('thumbnail')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield AttributeField::new('scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
    }
}
