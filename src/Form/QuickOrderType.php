<?php

namespace Base\Marketplace\Form;

use Base\Service\FormGuard;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A product bought in one step (Service\QuickOrder): the buyer's e-mail
 * address - none for a member signed in, the order is theirs - and where to
 * come back to. Public, with no account: guarded as glitchr/omnibase guards
 * a form (its option `guard`, action "quick_order": a trap, the time it
 * takes, the lists, the captcha when the site has glitchr/omniguard); a
 * form's own trap only where the host's core is from before the guard.
 *
 * Printed by @Marketplace/client/_quick_order.html.twig, which asks
 * marketplace_quick_order_form() for it.
 */
class QuickOrderType extends AbstractType
{
    public const NAME = 'quick_order';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['ask_email']) {
            $builder->add('email', EmailType::class, [
                'label' => '@marketplace.quick.email',
                'constraints' => [new Assert\NotBlank(message: '@marketplace.quick.error.email'), new Assert\Email(message: '@marketplace.quick.error.email'), new Assert\Length(max: 180, maxMessage: '@marketplace.quick.error.email')],
                'attr' => ['autocomplete' => 'email', 'maxlength' => 180, 'placeholder' => '@marketplace.quick.email_placeholder'],
            ]);
        }
        // Where a refusal brings the buyer back: a path of this site (the controller checks it).
        $builder->add('back', HiddenType::class, ['data' => $options['back']]);

        if (!class_exists(FormGuard::class)) {
            // A glitchr/omnibase from before the forms' guard: the form's own trap, read by the controller.
            $builder->add('website', TextType::class, ['required' => false, 'label' => false,
                'row_attr' => ['class' => 'marketplace-quick-trap', 'aria-hidden' => 'true'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'marketplace',
            'csrf_token_id' => 'marketplace_quick_order',
            'ask_email' => true,
            'back' => null,
        ]);
        $resolver->setAllowedTypes('ask_email', 'bool');
        $resolver->setAllowedTypes('back', ['null', 'string']);
        if (class_exists(FormGuard::class)) {
            // glitchr/omnibase's forms' guard: the buyer's address is the field "email"; there is no name.
            $resolver->setDefault('guard', ['action' => 'quick_order', 'email' => 'email', 'name' => '']);
        }
    }

    public function getBlockPrefix(): string
    {
        return self::NAME;
    }
}
