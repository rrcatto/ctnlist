<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Campaign\MessageContent;
use App\Form\Model\MessageDraft;
use App\Validator\WholeNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The message editor. `lists` (active lists) and `templates` supply the
 * choices; the HTML part carries data-html-editor for CKEditor. Only the
 * subject is required.
 */
final class MessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $lists = [];
        foreach ($options['lists'] as $list) {
            $lists[$list['l_name']] = $list['l_id'];
        }
        $templates = ['none' => 0];
        foreach ($options['templates'] as $template) {
            $templates[$template['t_name'] !== '' ? $template['t_name'] : 'Template ' . $template['t_id']] = $template['t_id'];
        }
        $text = ['empty_data' => '', 'required' => false];
        $number = ['empty_data' => '0', 'required' => false, 'invalid_message' => WholeNumber::NOT_A_NUMBER];
        $builder
            ->add('muid', HiddenType::class)
            ->add('subject', TextType::class, ['empty_data' => '', 'label' => 'Subject',
                'attr' => ['class' => 'form-control-lg', 'maxlength' => MessageContent::SUBJECT_MAX, 'placeholder' => 'The subject line of the email']])
            ->add('html', TextareaType::class, $text + ['label' => 'HTML part', 'attr' => ['rows' => 20, 'data-html-editor' => '', 'placeholder' => 'Write the message here']])
            ->add('text', TextareaType::class, $text + ['label' => 'Text part', 'help' => 'Plain-text alternative for mail clients that do not show HTML.', 'attr' => ['rows' => 12, 'class' => 'font-monospace']])
            ->add('listIds', ChoiceType::class, ['label' => 'To lists', 'choices' => $lists, 'multiple' => true, 'expanded' => true, 'required' => false,
                'invalid_message' => 'Choose lists from the list.'])
            // empty_data: a submission without the field means "none", as an empty number means 0 (never a null for the model).
            ->add('templateId', ChoiceType::class, ['label' => 'Template', 'choices' => $templates, 'placeholder' => false, 'required' => false, 'empty_data' => '0',
                'invalid_message' => 'Choose a template from the list.'])
            ->add('fromName', TextType::class, $text + ['label' => 'From name', 'attr' => ['maxlength' => 100]])
            ->add('fromAddress', TextType::class, $text + ['label' => 'From address', 'attr' => ['maxlength' => 254, 'inputmode' => 'email']])
            ->add('priority', IntegerType::class, $number + ['label' => 'Priority'])
            ->add('maxSend', IntegerType::class, $number + ['label' => 'Maximum emails to send', 'help' => 'The queue stops sending this message once this many have been sent (0 sends none).', 'attr' => ['min' => 0]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MessageDraft::class]);
        $resolver->setRequired(['lists', 'templates']);
        $resolver->setAllowedTypes('lists', 'array');
        $resolver->setAllowedTypes('templates', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'message';
    }
}
