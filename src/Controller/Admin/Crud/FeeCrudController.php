<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Market\Controller\Admin\AbstractMarketCrudController;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractActionAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractRuleAdapter;
use Base\Entity\Layout\Attribute\Adapter\Common\AbstractScopeAdapter;
use Base\Field\AttributeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Market\Entity\Sales\Fee;

/**
 * Additional fees on an order (handling, small-order surcharge...): where
 * they apply (scopes), when (rules) and how much (actions).
 */
class FeeCrudController extends AbstractMarketCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKET_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return Fee::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-invoice-dollar';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label')->setColumns(6);
        yield AttributeField::new('scopes')->setFilter(AbstractScopeAdapter::class)->hideOnIndex();
        yield AttributeField::new('rules')->setFilter(AbstractRuleAdapter::class)->hideOnIndex();
        yield AttributeField::new('actions')->setFilter(AbstractActionAdapter::class)->hideOnIndex();
    }
}
