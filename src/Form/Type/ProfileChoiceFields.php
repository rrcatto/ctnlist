<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Subscriber\ProfileOptions;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The gender, province and country selects shared by the subscriber forms.
 * A stored value that is not in ProfileOptions stays selectable, so saving
 * never drops it; no choice submits null.
 */
final class ProfileChoiceFields
{
    public static function add(FormBuilderInterface $builder, ?string $gender, ?string $province, ?string $country): void
    {
        $builder
            ->add('gender', ChoiceType::class, ['label' => 'Gender', 'required' => false, 'placeholder' => '', 'choices' => self::choices(ProfileOptions::GENDERS, $gender)])
            ->add('province', ChoiceType::class, ['label' => 'Province', 'required' => false, 'placeholder' => 'Select Province', 'choices' => self::choices(ProfileOptions::PROVINCES, $province)])
            ->add('country', ChoiceType::class, ['label' => 'Country', 'required' => false, 'placeholder' => 'Select Country', 'choices' => self::choices(ProfileOptions::COUNTRIES, $country)]);
    }

    /**
     * @param list<string> $options
     * @return array<string, string>
     */
    private static function choices(array $options, ?string $current): array
    {
        $choices = array_combine($options, $options);
        if ($current !== null && $current !== '' && !isset($choices[$current])) {
            $choices[$current] = $current;
        }
        unset($choices['']);
        return $choices;
    }
}
