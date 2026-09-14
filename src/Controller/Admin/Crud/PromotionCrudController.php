<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Market\Entity\Sales\Discount\Promotion;

/**
 * Promotions run by themselves between two dates, highest priority first:
 * an event, a sale. Rules say when they hold (a minimum total...), scopes
 * which lines they touch (a store, a product...), actions what comes off.
 */
class PromotionCrudController extends AbstractCrudController
{
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
        yield TextField::new('label')->setColumns(6);
        yield IntegerField::new('priority')->setColumns(2);
        yield DateTimeField::new('validAt')->setColumns(2);
        yield DateTimeField::new('expiredAt')->setColumns(2);
        yield AttributeField::new('rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
        yield AttributeField::new('scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('actions')->setFilter(AbstractActionAdapter::class)->hideOnIndex();
    }
}
