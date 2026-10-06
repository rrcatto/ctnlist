<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A select of the given `lists` ("Name (CODE)"), with the list id as value. */
final class ListChoiceType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
        $resolver->setDefaults([
            'label' => 'List',
            'invalid_message' => 'Choose a list from the list.',
            'choices' => static function (Options $options): array {
                $choices = [];
                foreach ($options['lists'] as $list) {
                    $choices[$list['l_name'] . ' (' . $list['l_shortcode'] . ')'] = $list['l_id'];
                }
                return $choices;
            },
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
