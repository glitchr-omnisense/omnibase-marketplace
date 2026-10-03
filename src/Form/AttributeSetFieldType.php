<?php

namespace Base\Marketplace\Form;

use Base\Entity\Layout\Attribute\Adapter\Common\AbstractAdapter;
use Base\Marketplace\Entity\Product\AttributeSet\Field;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** One attribute of a set in the back office: which, required, filtered on and how, shown. */
class AttributeSetFieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('adapter', EntityType::class, ['class' => AbstractAdapter::class, 'label' => '@marketplace.attribute_set.field.adapter'])
            ->add('required', CheckboxType::class, ['label' => '@marketplace.attribute_set.field.required', 'required' => false])
            ->add('filterable', CheckboxType::class, ['label' => '@marketplace.attribute_set.field.filterable', 'required' => false])
            ->add('filter', ChoiceType::class, ['label' => '@marketplace.attribute_set.field.filter', 'choices' => [
                '@marketplace.attribute_set.filter.choice' => Field::FILTER_CHOICE,
                '@marketplace.attribute_set.filter.range' => Field::FILTER_RANGE,
            ]])
            ->add('unit', \Symfony\Component\Form\Extension\Core\Type\TextType::class, ['label' => '@marketplace.attribute_set.field.unit', 'required' => false])
            ->add('visible', CheckboxType::class, ['label' => '@marketplace.attribute_set.field.visible', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Field::class, 'empty_data' => fn () => new Field(), 'translation_domain' => 'marketplace']);
    }
}
