<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\DateTimePickerField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Market\Entity\Product;
use Base\Market\Entity\Review;
use Base\Market\Entity\Review\Taxon;
use Base\Market\Entity\Store;

/**
 * Customer reviews: the product and store they are about, their rating and
 * pictures, and when they show (a review is a Thread: published or not).
 */
class ReviewCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Review::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-star-half-stroke';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('product')->add('store');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setColumns(6)->hideOnIndex();
        yield SelectField::new('product')->setClass(Product::class)->setColumns(6);
        yield SelectField::new('store')->setClass(Store::class)->setColumns(6)->hideOnIndex();
        yield IntegerField::new('rating')->setRequired(false)->setColumns(3);
        yield DateTimePickerField::new('publishedAt')->setRequired(false)->setColumns(3);
        yield SelectField::new('taxa')->setClass(Taxon::class)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield ImageField::new('pictures')->setMultipleFiles()->setRequired(false)->hideOnIndex();
        yield TextareaField::new('excerpt')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('content')->setRequired(false)->hideOnIndex();
    }
}
