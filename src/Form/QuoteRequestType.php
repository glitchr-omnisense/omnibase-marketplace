<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\TradeDirection;
use Base\Form\Type\PrivacyType;
use Base\Marketplace\Model\QuoteRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What a professional fills in to ask for a quote: who, which company
 * (checked: a SIRET at the State's register, an EU VAT number at VIES),
 * what, which way, on which terms, where, how much, for when. The trade
 * fields are left out with `trade: false` (a studio's quote needs none).
 * Options: phone, attachments (files, checked by Service\Attachments),
 * privacy / privacy_consent / privacy_parameters (glitchr/omnibase's
 * PrivacyType). Guarded as glitchr/omnibase guards a form (its option
 * `guard`, action "quote"): a trap, the time it takes, the lists, the captcha
 * when the site has glitchr/omniguard - in place of the form's own trap.
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
        ;
        if ($options['phone']) {
            $builder->add('phone', TelType::class, ['label' => '@marketplace.quote.form.phone', 'required' => false, 'attr' => ['autocomplete' => 'tel']]);
        }
        $builder
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

        if ($options['attachments']) {
            // Checked in the controller by Service\Attachments (number, weight, kind): the limits are the shop's configuration.
            $builder->add('files', FileType::class, [
                'label' => '@marketplace.quote.form.files',
                'help' => '@marketplace.quote.form.files_help',
                'required' => false,
                'multiple' => true,
            ]);
        }
        if (false !== $options['privacy'] || $options['privacy_consent']) {
            // glitchr/omnibase's notice, and the box to tick when the shop asks for it.
            $builder->add('privacy', PrivacyType::class, [
                'notice' => $options['privacy'],
                'notice_parameters' => $options['privacy_parameters'],
                'consent' => $options['privacy_consent'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuoteRequest::class,
            'translation_domain' => 'marketplace',
            'trade' => true,
            'preferred_countries' => ['FR', 'JP'],
            'phone' => true,
            'attachments' => true,
            // glitchr/omnibase's forms' guard (Base\Service\FormGuard): the sender's name is contactName.
            'guard' => ['action' => 'quote', 'name' => 'contactName'],
            // true: omnibase's notice; a translation key: the shop's own; false: none.
            'privacy' => true,
            'privacy_consent' => false,
            'privacy_parameters' => [],
        ]);
        $resolver->setAllowedTypes('trade', 'bool');
        foreach (['phone', 'attachments', 'privacy_consent'] as $option) {
            $resolver->setAllowedTypes($option, 'bool');
        }
        $resolver->setAllowedTypes('privacy', ['bool', 'string']);
        $resolver->setAllowedTypes('privacy_parameters', 'array');
    }
}
