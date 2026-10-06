<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\SignInRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SignInType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email address',
            'empty_data' => '',
            'attr' => ['maxlength' => 254, 'autocomplete' => 'email'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SignInRequest::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'signin';
    }
}
