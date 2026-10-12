<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Marketplace\Entity\Sales\Discount\Promotion;

/**
 * Promotions run by themselves between two dates, highest priority first:
 * an event, a sale. Rules say when they hold (a minimum total...), scopes
 * which lines they touch (a store, a product...), actions what comes off.
 */
class PromotionCrudController extends AbstractMarketplaceCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return Promotion::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-percent';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label', '@marketplace.admin.field.label')->setColumns(6);
        yield IntegerField::new('priority', '@marketplace.admin.field.priority')->setColumns(2);
        yield DateTimeField::new('validAt', '@marketplace.admin.field.valid_at')->setColumns(2);
        yield DateTimeField::new('expiredAt', '@marketplace.admin.field.expired_at')->setColumns(2);
        yield AttributeField::new('rules', '@marketplace.admin.field.rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
        yield AttributeField::new('scopes', '@marketplace.admin.field.scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('actions', '@marketplace.admin.field.actions')->setFilter(AbstractActionAdapter::class)->hideOnIndex();
    }
}
