<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Invite a subscriber to a list, on their page (SubscriberAdmin::invite()): data ['listId' => ?int]. */
final class ListInvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'label' => 'Invite to a list',
            'placeholder' => 'Choose a list', 'required' => true, 'constraints' => [new Assert\NotNull(message: 'Choose a list.')],
            'help' => 'Adds a pending membership, if they have none of that list yet, and emails them the invitation to confirm it.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'invitation';
    }
}
