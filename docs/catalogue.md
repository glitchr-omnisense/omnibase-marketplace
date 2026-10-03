---
title: Brands, typed sheets, filters, pairings
order: 20
---

# Brands, typed sheets, filters, pairings

What a catalogue needs beyond a title and a price, for any trade - the
example is a wine merchant's cellar, but nothing here knows about wine: the
wine is data.

## Brand

`Base\Marketplace\Entity\Brand` is a thread (its title the name, its content
the story, translated like any thread) with a logo, a country, a region, a
gallery and a website. `Product::$brand` names it; a variant reads its
principal's.

```php
$estate = (new Brand('Château Lafleur-Gazin'))->setCountry('FR')->setRegion('Pomerol');
$product->setBrand($estate);
```

A brand's page is the application's: `marketplace.brand_route` names the
route taking `{slug}` (`Brand::__toLink()`); null, brands have no link.
The catalogue synchronisation files Shopify's vendor and WooCommerce's brand
under the brand of that name, created when missing.

Back office: `BrandCrudController` (Boutique › Marques).

## AttributeSet: a typed sheet per taxon, without code

The attributes are glitchr/omnibase's (an adapter: its code, its label in
each language, its type - text, number, choice...). An `AttributeSet` says
which ones a kind of product has, for a `Product\Taxon` (a taxon without a
set takes its parent's; a set without taxon is every product's), each in a
`Field`: its position, `required`, `filterable` and how (`choice` among the
values met, or a `range`), its `unit` ("% vol.", "°C") and whether the
product's page shows it.

```php
$appellation = new TextAdapter('Appellation', 'appellation');
$alcohol = new NumberAdapter('Degré', 'alcohol');
$set = new AttributeSet('Vin', $wines);
$set->addField(new Field($appellation, filterable: true));
$set->addField(new Field($alcohol, filterable: true, filter: Field::FILTER_RANGE, unit: '% vol.'));

$attribute = new Product\Attribute($appellation, 'Pomerol');
$product->addAttribute($attribute->setProduct($product));
$product->getAttributeValue('appellation');   // "Pomerol" (a variant without it reads its principal's)
```

For a wine: appellation, region, grapes, colour, alcohol, serving temperature,
keeping, tasting notes; its vintages and formats are `Variant`s.

Back office: `AttributeSetCrudController` (Boutique › Fiches types).

### The list's filters

`Service\AttributeFilters` builds the filters from the set's filterable
fields and applies the visitor's choice, given in the query string:

```php
$selection = $filters->selection($request);            // ?f[colour][]=Rouge&f[alcohol][min]=13
$facets = $filters->facets($products, $set, $locale);  // [{code, label, filter, unit, values: {Rouge: 4, Blanc: 2}, min, max}]
$shown = $filters->apply($products, $selection, $locale);
```

A value holding a list ("Merlot, Cabernet franc") counts once per item. It
works in memory over the products given - right for a few hundred
references; a large catalogue filters in Typesense (`glitchr/typesense-bundle`).

In a template, a product's sheet:

```twig
{% for row in marketplace_attributes(product, app.request.locale) %}
    <dt>{{ row.label }}</dt><dd>{{ row.value }} {{ row.unit }}</dd>
{% endfor %}
{% set set = marketplace_attribute_set(product) %}
```

## Pairings, cross-sells, upsells

`Product\Association`: a product, a target, a type (`AssociationType::PAIRING`,
`CROSS_SELL`, `UPSELL`), a note by language and a position.

```php
$margaux->associate($comte, AssociationType::PAIRING, ['fr' => 'Sur un comté de 24 mois', 'en' => 'With a 24-month Comté']);
```

```twig
{% for association in marketplace_associations(product, 'pairing') %}
    <a href="{{ association.target.__toLink() }}">{{ association.target.title }}</a> {{ association.note(app.request.locale) }}
{% endfor %}
```

Only targets still for sale are listed. `@Marketplace/client/_product_trade.html.twig`
shows the brand, the sheet, the lots, the health notice, the pairings and the
link to a quotation.

## A blog post's products

A thread (a blog post of omnibase/blog, an event of omnibase/agenda) features
products through glitchr/omnibase's `Thread::$connexes`, in either direction:

```twig
{% for product in marketplace_products_of(post) %}…{% endfor %}
```

A food-and-wine pairing is then an article citing its bottles.

## Upgrading an application

New tables `marketplace_attribute_set`, `marketplace_attribute_set_field`,
`marketplace_product_association`, `marketplace_quote`, `marketplace_quote_line`,
`marketplace_platform_link`, the `Brand` thread's table; new columns on the
product (`brand_id`, `packSize`, `minimumQuantity`, `ageRestricted`), on the
product taxon (`ageRestricted`) and on the order (`quoteReference`):

```
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```
