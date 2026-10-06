<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\TemplateDraft;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The template editor; the HTML part carries data-html-editor for CKEditor. */
final class TemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $text = ['empty_data' => '', 'required' => false];
        $builder
            ->add('name', TextType::class, $text + ['label' => 'Template name', 'attr' => ['maxlength' => 100]])
            ->add('html', TextareaType::class, $text + ['label' => 'HTML part', 'attr' => ['rows' => 20, 'data-html-editor' => '']])
            ->add('text', TextareaType::class, $text + ['label' => 'Text part', 'attr' => ['rows' => 15, 'class' => 'font-monospace']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TemplateDraft::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'template';
    }
}
