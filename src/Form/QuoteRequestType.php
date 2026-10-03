<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\TradeDirection;
use Base\Marketplace\Model\QuoteRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What a professional fills in to ask for a quote: who, which company
 * (checked: a SIRET at the State's register, an EU VAT number at VIES),
 * what, which way, on which terms, where, how much, for when. The trade
 * fields are left out with `trade: false` (a studio's quote needs none).
 */
class QuoteRequestType extends AbstractType
{
    /**
     * The texts name their domain (@marketplace.…): omnibase gives every field the
     * "fields" domain, which the form's own translation_domain does not reach.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contactName', TextType::class, ['label' => '@marketplace.quote.form.name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => '@marketplace.quote.form.email', 'attr' => ['autocomplete' => 'email']])
            ->add('companyName', TextType::class, ['label' => '@marketplace.quote.form.company', 'required' => false, 'attr' => ['autocomplete' => 'organization']])
            ->add('siret', TextType::class, [
                'label' => '@marketplace.company.siret',
                'required' => false,
                'help' => '@marketplace.company.siret_help',
                'attr' => ['inputmode' => 'numeric', 'autocomplete' => 'off', 'data-controller' => 'siret', 'data-action' => 'siret#check', 'data-siret-url-value' => '/api/company/'],
            ])
            ->add('vatNumber', TextType::class, ['label' => '@marketplace.quote.form.vat', 'required' => false, 'help' => '@marketplace.quote.form.vat_help']);

        if ($options['trade']) {
            $builder
                ->add('direction', EnumType::class, ['class' => TradeDirection::class, 'label' => '@marketplace.quote.form.direction', 'required' => false, 'placeholder' => '—', 'choice_label' => fn (TradeDirection $d) => '@marketplace.quote.direction.'.$d->value])
                ->add('country', CountryType::class, ['label' => '@marketplace.quote.form.country', 'required' => false, 'placeholder' => '—', 'preferred_choices' => $options['preferred_countries']])
                ->add('incoterm', EnumType::class, ['class' => Incoterm::class, 'label' => '@marketplace.quote.form.incoterm', 'required' => false, 'placeholder' => '@marketplace.quote.form.incoterm_unknown', 'choice_label' => fn (Incoterm $i) => $i->value.' — '.'@marketplace.quote.incoterm.'.$i->value, 'help' => '@marketplace.quote.form.incoterm_help'])
                ->add('place', TextType::class, ['label' => '@marketplace.quote.form.place', 'required' => false])
                ->add('volume', TextType::class, ['label' => '@marketplace.quote.form.volume', 'required' => false, 'attr' => ['placeholder' => '@marketplace.quote.form.volume_placeholder']])
                ->add('targetDate', DateType::class, ['label' => '@marketplace.quote.form.target_date', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
                ->add('deliveryAddress', TextareaType::class, ['label' => '@marketplace.quote.form.delivery', 'required' => false, 'attr' => ['rows' => 3]]);
        }

        $builder
            ->add('title', TextType::class, ['label' => '@marketplace.quote.form.title', 'attr' => ['placeholder' => '@marketplace.quote.form.title_placeholder']])
            ->add('request', TextareaType::class, [
                'label' => '@marketplace.quote.form.request',
                'help' => '@marketplace.quote.form.request_help',
                'attr' => ['rows' => 7],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequest::class,
            'translation_domain' => 'marketplace',
            'trade' => true,
            'preferred_countries' => ['FR', 'JP'],
        ]);
        $resolver->setAllowedTypes('trade', 'bool');
    }
}
