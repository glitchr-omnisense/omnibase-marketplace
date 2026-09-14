<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Market\Entity\Sales\Discount\Coupon;

/**
 * Coupons: a code members type in their cart. A quota caps how many orders
 * may use it (per member too), an owner reserves it to one member, and
 * "individual use" keeps it from mixing with anything else.
 */
class CouponCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Coupon::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-ticket';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('code')->setColumns(3);
        yield TextField::new('label')->setColumns(5);
        yield DateTimeField::new('validAt')->setColumns(2);
        yield DateTimeField::new('expiredAt')->setColumns(2);
        yield IntegerField::new('quota')->setColumns(2);
        yield IntegerField::new('quotaPerCustomer')->setColumns(2);
        yield BooleanField::new('individualUse')->setColumns(2);
        // A plain member picker: an AssociationField would embed the whole member form.
        yield SelectField::new('owner')->setColumns(6)->hideOnIndex();
        yield AttributeField::new('rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
        yield AttributeField::new('scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('actions')->setFilter(AbstractActionAdapter::class)->hideOnIndex();
    }
}
