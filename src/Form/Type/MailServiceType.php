<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Connect to the mail service (Delivery page, Set up sending): data ['service' => string]. The
 * address is asked for only while none is known (`ask_address`); otherwise the form is the
 * Connect button alone, and ConnectFlow uses the configured address. Its form is checked here;
 * ConnectFlow::serviceOrigin() decides whether it is an address a connection can be made to.
 */
final class MailServiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['ask_address']) {
            return;
        }
        $builder->add('service', TextType::class, [
            'label' => 'Mail service address',
            'empty_data' => '',
            'constraints' => [
                new Assert\NotBlank(message: 'Enter the mail service\'s address.'),
                new Assert\Length(max: 300),
                new Assert\Regex(pattern: '/[\x00-\x20\x7F]/', match: false, message: 'An address has no spaces.'),
            ],
            'help' => 'The address you sign in to at the mail service, for example https://mail.example.org. It is kept, so you give it once.',
            'attr' => ['maxlength' => 300, 'placeholder' => 'https://', 'inputmode' => 'url', 'autocomplete' => 'off', 'spellcheck' => 'false'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data' => ['service' => ''], 'ask_address' => true]);
        $resolver->setAllowedTypes('ask_address', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'mail_service';
    }
}
