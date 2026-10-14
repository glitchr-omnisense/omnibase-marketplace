<?php

namespace Tests\Base\Marketplace\Controller;

use Base\Admin\Test\CrudFieldsTestCase;
use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;
use Base\Marketplace\Controller\Admin\Crud\BarcodeStandardCrudController;

/**
 * The fields marked required on this bundle's back office screens, against
 * what its records need (see CrudFieldsTestCase): only those the record
 * cannot do without, all of them, an optional one able to take an empty
 * value, one field per property, a slug following its title.
 */
final class CrudRequiredFieldsTest extends CrudFieldsTestCase
{
    protected static function sourceDirectory(): string
    {
        return __DIR__.'/../../src';
    }

    protected static function requiredByTheScreen(): array
    {
        return [
            ProductCrudController::class => [
                'parent' => "a product is sold in a store: its page, its basket and its orders are the store's",
            ],
            BarcodeStandardCrudController::class => [
                'icon' => "declared required by omnibase's AdapterCrudController, the core's to make optional (its column is nullable)",
            ],
        ];
    }
}
