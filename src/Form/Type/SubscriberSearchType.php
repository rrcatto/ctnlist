<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A GET search for subscribers (?q=…): email, name or UUID. No CSRF token,
 * as it changes nothing; the query stays in the URL.
 */
final class SubscriberSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('q', SearchType::class, [
            'label' => 'Find a subscriber',
            'required' => false,
            'empty_data' => '',
            'help' => $options['help'],
            'constraints' => [new Assert\Length(max: 254, maxMessage: 'Search for at most {{ limit }} characters.')],
            'attr' => ['placeholder' => 'Email, first or last name, or UUID'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['method' => 'GET', 'csrf_protection' => false, 'help' => null, 'allow_extra_fields' => true]);
    }

    /** Unprefixed field names, so the query string is just ?q=. */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
