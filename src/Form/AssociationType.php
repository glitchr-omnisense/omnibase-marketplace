<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Association;
use Base\Marketplace\Enum\AssociationType as Kind;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One pairing, cross-sell or upsell of a product in the back office, with its note in each language of the site. */
class AssociationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, ['class' => Kind::class, 'label' => '@marketplace.association.type', 'choice_label' => fn (Kind $k) => '@marketplace.association.kind.'.$k->value])
            ->add('target', EntityType::class, ['class' => Product::class, 'label' => '@marketplace.association.target']);
        foreach ($options['locales'] as $locale) {
            $builder->add('note_'.$locale, TextType::class, [
                'label' => strtoupper($locale),
                'required' => false,
                'getter' => fn (Association $a) => $a->getNotes()[$locale] ?? null,
                'setter' => fn (Association $a, ?string $note) => $a->setNote($locale, $note),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Association::class,
            'empty_data' => fn () => new Association(),
            'translation_domain' => 'marketplace',
            'locales' => ['fr', 'en'],
        ]);
    }
}
