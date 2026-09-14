<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Market\Entity\Order\Method\PaymentMethod;

/**
 * Admin CRUD for the payment methods offered at checkout. The gateway is the
 * name() of a service tagged market.payment_gateway ("manual" ships with the
 * bundle; an application adds its own).
 */
class PaymentMethodCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PaymentMethod::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-credit-card';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield TextField::new('gatewayFactory', 'Gateway')->setColumns(6);
        yield IntegerField::new('refundFee')->setColumns(3)->hideOnIndex();
    }
}
