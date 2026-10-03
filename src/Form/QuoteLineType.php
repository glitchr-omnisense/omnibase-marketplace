<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One line of a quote in the back office: a product or a free label, lots, units per lot, the price of a lot (cents). */
class QuoteLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', EntityType::class, ['class' => Product::class, 'label' => '@marketplace.quote.line.product', 'required' => false, 'placeholder' => '@marketplace.quote.line.free'])
            ->add('label', TextType::class, ['label' => '@marketplace.quote.line.label', 'required' => false])
            ->add('quantity', IntegerType::class, ['label' => '@marketplace.quote.line.quantity', 'attr' => ['min' => 1]])
            ->add('lotSize', IntegerType::class, ['label' => '@marketplace.quote.line.lot_size', 'attr' => ['min' => 1]])
            ->add('lotPrice', IntegerType::class, ['label' => '@marketplace.quote.line.lot_price', 'help' => '@marketplace.quote.line.lot_price_help', 'attr' => ['min' => 0]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteLine::class,
            'empty_data' => fn () => new QuoteLine(),
            'translation_domain' => 'marketplace',
        ]);
    }
}
