<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Field\CountryField;
use Base\Field\EditorField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\SlugField;
use Base\Field\StateField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Entity\Brand;

/** The brands: the estates and houses, the makers - their story, logo, place and pictures. */
class BrandCrudController extends AbstractMarketplaceCrudController
{
    public static function getEntityFqcn(): string
    {
        return Brand::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-award';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ImageField::new('logo', '@marketplace.brand.logo')->setColumns(3);
        yield TextField::new('title', '@marketplace.brand.name')->setColumns(6);
        yield StateField::new('state')->setColumns(3);
        yield SlugField::new('slug')->setColumns(4)->hideOnIndex();
        yield CountryField::new('country', '@marketplace.brand.country')->setColumns(4);
        yield TextField::new('region', '@marketplace.brand.region')->setColumns(4);
        yield TextField::new('website', '@marketplace.brand.website')->setColumns(6)->hideOnIndex();
        yield TextField::new('headline', '@marketplace.brand.headline')->setColumns(6)->hideOnIndex();
        yield TextareaField::new('excerpt', '@marketplace.brand.excerpt')->hideOnIndex();
        yield EditorField::new('content', '@marketplace.brand.story')->hideOnIndex();
        yield ImageField::new('gallery', '@marketplace.brand.gallery')->hideOnIndex();
    }
}
