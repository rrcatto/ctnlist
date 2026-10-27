<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\CattoMail\AddressValidation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Which list's members to validate with catto-mail, and which of them (owner decision
 * 2026-10-27: confirmed members by default, since only they receive campaigns and every
 * address counts against the daily validation limit): data ['listId' => ?int, 'members' => string].
 */
final class ValidationRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $members = [];
        foreach (AddressValidation::MEMBERS as $value => [$label]) {
            $members[$label] = $value;
        }
        $builder
            ->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'label' => 'Validate the members of',
                'placeholder' => false, 'constraints' => [new Assert\NotNull(message: 'Choose a list.')],
                'help' => 'Addresses go to the mail service in jobs of up to 10,000.'])
            ->add('members', ChoiceType::class, ['label' => 'Which members', 'choices' => $members, 'expanded' => true,
                'constraints' => [new Assert\NotBlank(message: 'Choose which members to validate.')]]);
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
