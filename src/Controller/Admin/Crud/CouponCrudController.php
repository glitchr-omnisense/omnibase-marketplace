<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
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
use Base\Marketplace\Entity\Sales\Discount\Coupon;

/**
 * Coupons: a code members type in their cart. A quota caps how many orders
 * may use it (per member too), an owner reserves it to one member, and
 * "individual use" keeps it from mixing with anything else.
 */
class CouponCrudController extends AbstractMarketplaceCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

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
        yield TextField::new('code', '@marketplace.admin.field.code')->setColumns(3);
        yield TextField::new('label', '@marketplace.admin.field.label')->setColumns(5);
        yield DateTimeField::new('validAt', '@marketplace.admin.field.valid_at')->setColumns(2);
        yield DateTimeField::new('expiredAt', '@marketplace.admin.field.expired_at')->setColumns(2);
        yield IntegerField::new('quota', '@marketplace.admin.field.quota')->setColumns(2);
        yield IntegerField::new('quotaPerCustomer', '@marketplace.admin.field.quota_per_customer')->setColumns(2);
        yield BooleanField::new('individualUse', '@marketplace.admin.field.individual_use')->setColumns(2);
        // A plain member picker: an AssociationField would embed the whole member form.
        yield SelectField::new('owner', '@marketplace.admin.field.owner')->setColumns(6)->hideOnIndex();
        yield AttributeField::new('rules', '@marketplace.admin.field.rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
        yield AttributeField::new('scopes', '@marketplace.admin.field.scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('actions', '@marketplace.admin.field.actions')->setFilter(AbstractActionAdapter::class)->hideOnIndex();
    }
}
