<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The hidden trap field of the sign-in and subscribe forms (App\Http\BotCheck,
 * named BotCheck::TRAP_FIELD): kept off-screen by app.css (.app-trap, not
 * display: none, which form bots skip), out of the tab order and hidden from
 * screen readers, so people leave it empty and form-filling bots do not.
 */
final class BotTrapType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => 'Leave this field empty',
            'required' => false,
            'mapped' => false,
            'empty_data' => '',
            'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
            'row_attr' => ['class' => 'app-trap', 'aria-hidden' => 'true'],
        ]);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
