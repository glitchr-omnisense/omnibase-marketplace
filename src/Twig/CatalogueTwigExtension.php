<?php

namespace Base\Marketplace\Twig;

use Base\Entity\Thread;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Association;
use Base\Marketplace\Enum\AssociationType;
use Base\Marketplace\Repository\Product\AttributeSetRepository;
use Base\Marketplace\Service\AgeGate;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The catalogue's pages from any template:
 *
 *   {% if marketplace_age_gate(product) %}{% include '@Marketplace/client/_age_gate.html.twig' %}{% endif %}
 *   {{ marketplace_age_minimum() }}                 18, 20 in Japanese...
 *   {{ marketplace_age_notice() }}                  the health notice, or null
 *   {% for row in marketplace_attributes(product) %}{{ row.label }}: {{ row.value }}{% endfor %}
 *   {% for a in marketplace_associations(product, 'pairing') %}{{ a.target }} - {{ a.note(app.request.locale) }}{% endfor %}
 *   {% for product in marketplace_products_of(post) %}…{% endfor %}   a blog post's wines
 */
final class CatalogueTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AgeGate $ageGate,
        private readonly AttributeSetRepository $sets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('marketplace_age_gate', [$this->ageGate, 'mustAsk']),
            new TwigFunction('marketplace_age_minimum', [$this->ageGate, 'minimumAge']),
            new TwigFunction('marketplace_age_notice', [$this->ageGate, 'notice']),
            new TwigFunction('marketplace_attribute_set', [$this->sets, 'forProduct']),
            new TwigFunction('marketplace_attributes', [$this, 'attributes']),
            new TwigFunction('marketplace_associations', [$this, 'associations']),
            new TwigFunction('marketplace_products_of', [$this, 'productsOf']),
        ];
    }

    /**
     * A product's sheet: the visible fields of its set, in order, with their
     * value in this language (a variant's own, else its principal's).
     *
     * @return list<array{code: string, label: string, value: string, unit: ?string}>
     */
    public function attributes(Product $product, ?string $locale = null): array
    {
        $set = $this->sets->forProduct($product->isVariant() && method_exists($product, 'getPrincipal') ? ($product->getPrincipal() ?? $product) : $product);
        $rows = [];
        foreach ($set?->getFields() ?? [] as $field) {
            if (!$field->isVisible() || null === $field->getCode()) {
                continue;
            }
            $value = $product->getAttributeValue($field->getCode(), $locale);
            $value = \is_array($value) ? implode(', ', $value) : trim((string) $value);
            if ('' === $value) {
                continue;
            }
            $rows[] = ['code' => $field->getCode(), 'label' => (string) $field, 'value' => $value, 'unit' => $field->getUnit()];
        }

        return $rows;
    }

    /** @return list<Association> what a product leads to, of a type when given ("pairing", "cross_sell", "upsell") */
    public function associations(Product $product, string|AssociationType|null $type = null): array
    {
        $type = \is_string($type) ? AssociationType::from($type) : $type;

        return array_values(array_filter($product->getAssociations($type)->toArray(), fn (Association $a) => $a->getTarget()?->isForSell()));
    }

    /**
     * The products a thread (a blog post, an event) features: those it
     * names among its connexes, and those naming it among theirs.
     *
     * @return list<Product>
     */
    public function productsOf(Thread $thread): array
    {
        $products = array_values(array_filter($thread->getConnexes()->toArray(), fn ($t) => $t instanceof Product));
        $naming = $this->entityManager->getRepository(Product::class)->createQueryBuilder('p')
            ->innerJoin('p.connexes', 'c')->andWhere('c = :thread')->setParameter('thread', $thread)
            ->getQuery()->getResult();
        foreach ($naming as $product) {
            if (!\in_array($product, $products, true)) {
                $products[] = $product;
            }
        }

        return $products;
    }
}
