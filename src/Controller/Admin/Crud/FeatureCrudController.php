<?php

namespace Base\Market\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\ColorField;
use Base\Field\IconField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\TranslationField;
use Base\Market\Entity\Product;
use Base\Market\Entity\Product\Feature;

/**
 * The product features shown as badges (washable, made in France...): an
 * icon or a picture, a label and a description per language. A feature
 * products still carry cannot be deleted.
 */
class FeatureCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Feature::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-certificate';
    }

    public function isDeletable(object $entity): bool
    {
        return 0 === \count($entity->getProducts());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TranslationField::new()->showOnIndex('label')->setFields([
            'label' => ['required' => true],
            'description' => ['required' => false],
        ])->setColumns(12);
        yield SlugField::new('slug')->setColumns(4);
        yield IconField::new('icon')->setTargetColor('color')->setColumns(4);
        yield ColorField::new('color')->hideOnIndex()->setColumns(4);
        yield ImageField::new('image')->setRequired(false)->setColumns(6);
        yield SelectField::new('threads', 'Products')->setClass(Product::class)->allowMultipleChoices()->renderAsCount()->onlyOnIndex();
    }
}
