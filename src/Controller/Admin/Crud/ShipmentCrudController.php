<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Method\ShippingMethod;
use Base\Market\Entity\Order\Shipment;

/**
 * The parcels sent for an order: their tracking number, method and the
 * items they hold. Kept once sent - a shipment is not deleted.
 */
class ShipmentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Shipment::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-truck-fast';
    }

    public function isDeletable(object $entity): bool
    {
        return false;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('order')->add('method');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('number', 'Tracking number')->setColumns(4);
        yield SelectField::new('order')->setClass(Order::class)->setColumns(4);
        yield SelectField::new('method')->setClass(ShippingMethod::class)->setColumns(4);
        yield AssociationField::new('items')->renderAsCount()->onlyOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
