<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Market\Controller\Admin\AbstractMarketCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\BooleanField;
use Base\Field\CurrencyField;
use Base\Field\IdField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Market\Entity\Store;
use Base\Field\SelectField;
use Base\Market\Entity\Product;
use Base\Market\Entity\Sales\Region;
use Base\Entity\User;

/** Admin CRUD for the stores: a name, the currency it trades in, open or shut. */
class StoreCrudController extends AbstractMarketCrudController
{
    /** VAT and prices: the shops' owners set them too (MARKET_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return Store::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-store';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('open');
    }

    /** A store orders were placed in stays: they point to it. */
    public function isDeletable(object $entity): bool
    {
        return 0 === \count($entity->getOrders());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield CurrencyField::new('currency')->setColumns(3);
        yield BooleanField::new('open')->setColumns(3);
        // Store::$regions and $products alias the thread's tags and children
        yield SelectField::new('tags', 'Regions')->setClass(Region::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        // VAT: the owner's choice, and only with a valid VAT number.
        yield BooleanField::new('chargesVat', 'Applique la TVA')->setColumns(3)->hideOnIndex();
        yield TextField::new('vatNumber', 'N° de TVA intracommunautaire')->setRequired(false)->setColumns(3)->hideOnIndex()
            ->setHelp('Sans numéro de TVA valide, la TVA ne peut pas être appliquée.');
        // Its owners read the whole marketplace in the back office (MarketplaceVoter).
        yield SelectField::new('owners')->setClass(User::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield SelectField::new('children', 'Products')->setClass(Product::class)->allowMultipleChoices()->renderAsCount()->onlyOnIndex();
        yield TextareaField::new('excerpt')->hideOnIndex();
    }
}
