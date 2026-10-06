<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\SubscribeRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Email address plus the list to subscribe to: a choice of `lists` (with
 * their descriptions), or a hidden field when there is only one.
 */
final class SubscribeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email address',
            'empty_data' => '',
            'attr' => ['maxlength' => 254, 'autocomplete' => 'email'],
        ]);
        $lists = $options['lists'];
        $choices = [];
        foreach ($lists as $list) {
            $choices[$list['l_name']] = $list['l_id'];
        }
        $builder->add('listId', ChoiceType::class, [
            'label' => 'List',
            'choices' => $choices,
            'expanded' => true,
            'placeholder' => false,
            'invalid_message' => 'Choose one of the lists.',
        ]);
        $builder->add('messageId', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SubscribeRequest::class]);
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'subscribe';
    }
}
