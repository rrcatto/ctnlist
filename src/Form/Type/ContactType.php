<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\ContactRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = ['empty_data' => ''];
        $builder
            ->add('name', TextType::class, $text + ['label' => 'Full name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, $text + ['label' => 'Email', 'attr' => ['autocomplete' => 'email']])
            ->add('cell', TelType::class, $text + ['label' => 'Cell', 'required' => false, 'attr' => ['autocomplete' => 'tel']])
            ->add('company', TextType::class, $text + ['label' => 'Company', 'required' => false, 'attr' => ['autocomplete' => 'organization']])
            ->add('website', TextType::class, $text + ['label' => 'Website', 'required' => false, 'attr' => ['autocomplete' => 'url']])
            ->add('topic', TextType::class, $text + ['label' => 'Topic / subject', 'required' => false])
            ->add('message', TextareaType::class, $text + ['label' => 'Message', 'attr' => ['rows' => 8]])
            ->add('suid', HiddenType::class)
            ->add('muid', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactRequest::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'contact';
    }
}
