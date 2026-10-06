<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\ProfileDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ProfileDetails|null $current */
        $current = $options['data'] ?? null;
        $text = ['empty_data' => '', 'required' => false];
        $builder
            ->add('firstName', TextType::class, $text + ['label' => 'First name', 'attr' => ['maxlength' => 100, 'autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, $text + ['label' => 'Last name', 'attr' => ['maxlength' => 100, 'autocomplete' => 'family-name']])
            ->add('phone', TelType::class, $text + ['label' => 'Cell', 'attr' => ['maxlength' => 30, 'autocomplete' => 'tel']])
            ->add('birthday', DateType::class, ['label' => 'Birthdate', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable',
                'invalid_message' => 'Enter a valid date.']);
        ProfileChoiceFields::add($builder, $current?->gender, $current?->province, $current?->country);
        $builder
            ->add('business', TextType::class, $text + ['label' => 'Company', 'attr' => ['maxlength' => 100, 'autocomplete' => 'organization']])
            ->add('url', TextType::class, $text + ['label' => 'Website', 'attr' => ['maxlength' => 253, 'autocomplete' => 'url']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileDetails::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'profile';
    }
}
