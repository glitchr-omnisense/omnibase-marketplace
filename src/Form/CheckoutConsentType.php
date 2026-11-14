<?php

namespace Base\Marketplace\Form;

use Base\Marketplace\Model\ConsentStatement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The statements a buyer makes before paying (Service\Consents): one box per statement, none
 * ticked beforehand, each one required - its label the very text that is recorded -, and the
 * version of the texts shown, so that a wording changed between the page and the click is
 * shown again rather than taken as accepted.
 *
 * Named "consent", inside the checkout's own form: that form's CSRF token covers it.
 */
class CheckoutConsentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ConsentStatement $statement */
        foreach ($options['statements'] as $statement) {
            $builder->add($statement->id, CheckboxType::class, [
                'label' => $statement->text,
                'translation_domain' => false,
                'required' => true,
                'data' => false,
                'constraints' => [new Assert\IsTrue(message: '@marketplace.consent.error.'.$statement->id)],
            ]);
        }
        $builder->add('version', HiddenType::class, [
            'data' => $options['version'],
            'constraints' => [new Assert\EqualTo(value: $options['version'], message: '@marketplace.consent.error.changed')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['statements', 'version']);
        $resolver->setAllowedTypes('statements', ConsentStatement::class.'[]');
        $resolver->setAllowedTypes('version', 'string');
        $resolver->setDefaults([
            'csrf_protection' => false,
            'translation_domain' => 'marketplace',
        ]);
    }
}
