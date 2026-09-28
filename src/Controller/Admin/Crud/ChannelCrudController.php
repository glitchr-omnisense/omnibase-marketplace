<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\ColorField;
use Base\Field\IconField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TranslationField;
use Base\Market\Entity\Product;
use Base\Market\Entity\Sales\Channel;

/**
 * The sales channels a product is published on (the storefront, a product
 * feed, an export): a product appears where its channels say.
 */
class ChannelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Channel::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-tower-broadcast';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TranslationField::new()->showOnIndex('label')->setFields(['label' => ['required' => true]])->setColumns(6);
        yield SlugField::new('slug')->setColumns(6);
        yield IconField::new('icon')->setTargetColor('color')->setColumns(3);
        yield ColorField::new('color')->hideOnIndex()->setColumns(3);
        yield SelectField::new('products')->setClass(Product::class)->allowMultipleChoices()->renderAsCount()->onlyOnIndex();
    }
}
