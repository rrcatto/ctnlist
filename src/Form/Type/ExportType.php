<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Validator\WholeNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The subscriber export (v5 /export/{offset}/{limit}?l=): data
 * ['listId' => ?int, 'offset' => int, 'limit' => int].
 */
final class ExportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'label' => 'List', 'required' => false, 'placeholder' => 'All subscribers',
                'help' => 'Export the members of one list, or every subscriber.'])
            ->add('offset', IntegerType::class, ['label' => 'Skip the first', 'empty_data' => '0', 'required' => false,
                'invalid_message' => WholeNumber::NOT_A_NUMBER, 'constraints' => [new WholeNumber(min: 0)]])
            ->add('limit', IntegerType::class, ['label' => 'At most', 'empty_data' => '10000000', 'required' => false,
                'invalid_message' => WholeNumber::NOT_A_NUMBER, 'constraints' => [new WholeNumber(min: 1)]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'export';
    }
}
