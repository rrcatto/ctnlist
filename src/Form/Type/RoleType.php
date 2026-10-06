<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\RoleDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Create (with a key) or edit (name and description) a custom role. */
final class RoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['create']) {
            $builder->add('key', TextType::class, [
                'empty_data' => '',
                'label' => 'Key',
                'help' => 'Lower-case letters, numbers, dots, dashes and underscores. The key cannot be changed later; the name and description can.',
                'attr' => ['maxlength' => 64, 'autocomplete' => 'off'],
            ]);
            // Keys are stored in lower case; validate what will be stored.
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (is_array($data) && is_string($data['key'] ?? null)) {
                    $data['key'] = strtolower(trim($data['key']));
                    $event->setData($data);
                }
            });
        }
        $builder
            ->add('name', TextType::class, ['empty_data' => '', 'label' => 'Name', 'attr' => ['maxlength' => 100]])
            ->add('description', TextType::class, ['empty_data' => '', 'label' => 'Description', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoleDetails::class,
            'create' => false,
            'validation_groups' => static fn($form): array => $form->getConfig()->getOption('create') ? ['Default', 'create'] : ['Default'],
        ]);
        $resolver->setAllowedTypes('create', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'role';
    }
}
