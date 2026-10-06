<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\ListDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Create (with a shortcode) or edit (name and description) a topic list. */
final class ListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['create']) {
            $builder->add('shortcode', TextType::class, [
                'empty_data' => '',
                'label' => 'Shortcode',
                'help' => '3–6 letters or numbers, stored in upper case. Used in links and headers; it cannot be changed later.',
                'attr' => ['class' => 'text-uppercase', 'maxlength' => 6, 'autocomplete' => 'off'],
            ]);
            // Shortcodes are stored in upper case; validate what will be stored.
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
                $data = $event->getData();
                if (is_array($data) && is_string($data['shortcode'] ?? null)) {
                    $data['shortcode'] = strtoupper(trim($data['shortcode']));
                    $event->setData($data);
                }
            });
        }
        $builder
            ->add('name', TextType::class, ['empty_data' => '', 'label' => 'Name', 'attr' => ['maxlength' => 100]])
            ->add('description', TextareaType::class, ['empty_data' => '', 'label' => 'Description', 'required' => false,
                'help' => 'Shown with the list on the subscribe page.', 'attr' => ['rows' => 4]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ListDetails::class,
            'create' => false,
            'validation_groups' => static fn($form): array => $form->getConfig()->getOption('create') ? ['Default', 'create'] : ['Default'],
        ]);
        $resolver->setAllowedTypes('create', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'list';
    }
}
