<?php

declare(strict_types=1);

namespace App\Form\Settings;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The main SMTP server (MAILER_DSN) as fields. The password is never
 * rendered back (always empty); leaving it empty keeps the stored one.
 */
final class SmtpServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, ['label' => 'Server', 'required' => false, 'constraints' => SettingConstraints::host(), 'attr' => ['placeholder' => 'mail.example.com', 'autocomplete' => 'off']])
            ->add('port', IntegerType::class, ['invalid_message' => SettingConstraints::NOT_A_NUMBER, 'label' => 'Port', 'required' => false, 'constraints' => SettingConstraints::port(), 'attr' => ['placeholder' => '587']])
            ->add('security', ChoiceType::class, ['label' => 'Security', 'required' => false, 'placeholder' => false, 'choices' => ['SMTP / STARTTLS' => 'smtp', 'SMTP over TLS' => 'smtps']])
            ->add('username', TextType::class, ['label' => 'Username', 'required' => false, 'attr' => ['autocomplete' => 'off']])
            ->add('password', PasswordType::class, ['label' => 'Password', 'required' => false, 'always_empty' => true, 'attr' => ['autocomplete' => 'new-password']])
            ->add('options', TextType::class, ['label' => 'Options', 'required' => false, 'attr' => ['placeholder' => 'e.g. verify_peer=0'],
                'constraints' => [new Assert\Regex(pattern: '/^[^\s#]*$/', message: 'Options may not contain spaces or #.')]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['label' => false]);
    }
}
