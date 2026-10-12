<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The address of the mail service to connect to (Delivery page): data
 * ['service' => string]. Its form is checked here; ConnectFlow::serviceOrigin()
 * decides whether it is an address a connection can be made to.
 */
final class MailServiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('service', TextType::class, [
            'label' => 'Mail service address',
            'empty_data' => '',
            'constraints' => [
                new Assert\NotBlank(message: 'Enter the mail service\'s address.'),
                new Assert\Length(max: 300),
                new Assert\Regex(pattern: '/[\x00-\x20\x7F]/', match: false, message: 'An address has no spaces.'),
            ],
            'help' => 'The address you sign in to the mail service at, for example https://mail.example.org.',
            'attr' => ['maxlength' => 300, 'placeholder' => 'https://', 'inputmode' => 'url', 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data' => ['service' => '']]);
    }

    public function getBlockPrefix(): string
    {
        return 'mail_service';
    }
}
