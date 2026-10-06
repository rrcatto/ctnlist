<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\QueueRotation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Data: QueueRotation. Option `messages`: the choosable messages
 * (MessageRepository::choices()); a value outside them is rejected.
 */
final class QueueRotationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{m_uniqid: string, m_subject: string}> $messages */
        $messages = $options['messages'];
        $subjects = array_column($messages, 'm_subject', 'm_uniqid');
        for ($slot = 1; $slot <= QueueRotation::SLOTS; $slot++) {
            $builder->add('message' . $slot, ChoiceType::class, [
                'label' => 'Message ' . $slot,
                'required' => false,
                'placeholder' => '– none –',
                'choices' => array_keys($subjects),
                'choice_label' => static fn(string $muid): string => $subjects[$muid] !== '' ? $subjects[$muid] : '(no subject) ' . $muid,
                'invalid_message' => 'Choose one of the listed messages.',
                'row_attr' => ['class' => 'col-md-6'],
            ]);
        }
        $builder->add('volume', IntegerType::class, [
            'label' => 'Total number of emails to queue',
            'attr' => ['min' => 1],
            'row_attr' => ['class' => 'col-md-6'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QueueRotation::class]);
        $resolver->setRequired('messages');
        $resolver->setAllowedTypes('messages', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'rotation';
    }
}
