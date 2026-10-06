<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The permissions a custom role grants: data ['permissions' => list<int>]
 * (acl_permissions ids). Who may grant what is RoleManager's rule.
 */
final class RolePermissionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['permissions'] as $permission) {
            $choices[$permission['ap_key']] = $permission['ap_id'];
        }
        $builder->add('permissions', ChoiceType::class, [
            'label' => false,
            'choices' => $choices,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'invalid_message' => 'Choose permissions from the list.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('permissions');
        $resolver->setAllowedTypes('permissions', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'role_permissions';
    }
}
