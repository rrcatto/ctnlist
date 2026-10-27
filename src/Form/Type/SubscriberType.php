<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\SubscriberProfile;
use App\Validator\WholeNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The subscriber form. Administrators (option `administrator`) also get the
 * priority; they invite to a list with ListInvitationType.
 */
final class SubscriberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var SubscriberProfile|null $current */
        $current = $options['data'] ?? null;
        $text = ['empty_data' => '', 'required' => false];
        $builder
            ->add('firstName', TextType::class, $text + ['label' => 'First name', 'attr' => ['maxlength' => 100, 'autocomplete' => 'off']])
            ->add('lastName', TextType::class, $text + ['label' => 'Last name', 'attr' => ['maxlength' => 100, 'autocomplete' => 'off']]);
        ProfileChoiceFields::add($builder, $current?->gender, $current?->province, $current?->country);
        if ($options['administrator']) {
            $builder
                ->add('priority', IntegerType::class, ['label' => 'Priority', 'required' => false, 'empty_data' => '0', 'invalid_message' => WholeNumber::NOT_A_NUMBER,
                    'help' => 'Saving sets the priority to this value plus 100 (the v5 engagement rule).']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SubscriberProfile::class, 'administrator' => false]);
        $resolver->setAllowedTypes('administrator', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'subscriber';
    }
}
