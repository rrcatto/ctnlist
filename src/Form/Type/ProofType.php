<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Validator\EmailAddress;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Where to send a proof: data ['email' => string]. Any valid address; it
 * does not need to be a subscriber. Empty means MAIL_TEST_ADDRESS.
 */
final class ProofType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Send the proof to',
            'required' => false,
            'empty_data' => '',
            'constraints' => [new EmailAddress()],
            'help' => 'Defaults to the configured test address (MAIL_TEST_ADDRESS). Merge fields are filled with test values (first name “Test”, last name “Recipient”); its links do not belong to any subscriber.',
            'attr' => ['maxlength' => 254, 'autocomplete' => 'email'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data' => ['email' => '']]);
    }

    public function getBlockPrefix(): string
    {
        return 'proof';
    }
}
