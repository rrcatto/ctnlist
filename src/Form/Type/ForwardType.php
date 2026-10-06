<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Campaign\ForwardService;
use App\Form\Model\ForwardRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ForwardType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emails', TextareaType::class, [
                'label' => 'Email addresses',
                'empty_data' => '',
                'help' => 'Up to ' . ForwardService::MAX_RECIPIENTS . ' addresses, separated by commas, spaces or new lines.',
                'attr' => ['rows' => $options['archive'] ? 4 : 6, 'autocomplete' => 'off'],
            ])
            ->add('token', HiddenType::class)
            ->add('muid', HiddenType::class);
        if ($options['archive']) {
            $builder->add('archiveId', HiddenType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ForwardRequest::class, 'archive' => false]);
        $resolver->setAllowedTypes('archive', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'forward';
    }
}
