<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Entity\Product\Option;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One option of a group in the back office: its label, what it adds to the price (cents, before VAT), preselected, available. */
class ProductOptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => '@marketplace.options.admin.label'])
            ->add('price', IntegerType::class, ['label' => '@marketplace.options.admin.price', 'required' => false, 'empty_data' => '0'])
            ->add('default', CheckboxType::class, ['label' => '@marketplace.options.admin.default', 'required' => false])
            ->add('available', CheckboxType::class, ['label' => '@marketplace.options.admin.available', 'required' => false])
            ->add('position', IntegerType::class, ['label' => '@marketplace.options.admin.position', 'required' => false, 'empty_data' => '0']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Option::class, 'empty_data' => fn () => new Option(), 'translation_domain' => 'marketplace']);
    }
}
