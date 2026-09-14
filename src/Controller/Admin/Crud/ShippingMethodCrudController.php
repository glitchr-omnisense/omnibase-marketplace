<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Market\Entity\Order\Method\ShippingMethod;

/**
 * Shipping methods offered at checkout for physical goods. Rate type
 * RATE_FLAT costs the unit price once, RATE_PRIORITY per shipping unit;
 * the tracking URL may hold {number}.
 */
class ShippingMethodCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ShippingMethod::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-truck';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield TextField::new('typeRate')->setColumns(3)->setHelp('RATE_FLAT, RATE_PRIORITY, RATE_INTERNATIONAL');
        yield IntegerField::new('unitPrice')->setColumns(3);
        yield CurrencyField::new('currency')->setColumns(2);
        yield IntegerField::new('deliveryTime')->setColumns(2)->setHelp('Jours');
        yield IntegerField::new('shippingDelay')->setColumns(2)->hideOnIndex();
        yield TextField::new('trackingUrl')->hideOnIndex()->setHelp('https://www.laposte.fr/outils/suivre-vos-envois?code={number}');
    }
}
