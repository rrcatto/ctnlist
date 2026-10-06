<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Which list's members to validate with catto-mail: data ['listId' => ?int]. */
final class ValidationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'label' => 'Validate the members of',
            'placeholder' => false, 'constraints' => [new Assert\NotNull(message: 'Choose a list.')],
            'help' => 'Every member who has not unsubscribed from the list, in jobs of up to 10,000 addresses.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'validation';
    }
}
