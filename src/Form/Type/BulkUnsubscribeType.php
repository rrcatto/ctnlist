<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\BulkUnsubscription;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class BulkUnsubscribeType extends AbstractType
{
    /** Scope => [label, help]. */
    public const SCOPES = [
        'list' => ['Remove from the selected list only', 'Unsubscribes each address from the list above.'],
        'domain' => ['Suppress entire domains', 'Adds each address’s domain to the global suppression list.'],
        'bounce' => ['These are bounces', 'Adds each address to the global suppression list as a bounce.'],
        'spam' => ['These are spam complainers', 'Adds each address to the global suppression list as a spam complaint.'],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $scopes = [];
        foreach (self::SCOPES as $value => [$label]) {
            $scopes[$label] = $value;
        }
        $builder
            ->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'required' => false, 'placeholder' => false])
            ->add('scope', ChoiceType::class, ['label' => 'What to do', 'choices' => $scopes, 'expanded' => true,
                'choice_attr' => static fn(string $value): array => ['data-help' => self::SCOPES[$value][1]]])
            ->add('reason', TextType::class, ['label' => 'Reason', 'empty_data' => '', 'required' => false, 'attr' => ['maxlength' => 255]])
            ->add('emails', TextareaType::class, ['label' => 'Email addresses', 'empty_data' => '', 'attr' => ['rows' => 12, 'class' => 'font-monospace'],
                'help' => 'Separate addresses with commas, spaces or new lines.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BulkUnsubscription::class]);
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'bulk_unsubscribe';
    }
}
