<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\BulkSubscription;
use App\Validator\WholeNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Bulk subscribe (pasted addresses) or, with `import`, an uploaded file. */
final class BulkSubscribeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('listId', ListChoiceType::class, ['lists' => $options['lists'], 'placeholder' => false])
            ->add('priority', IntegerType::class, ['label' => 'Priority', 'required' => false, 'empty_data' => '0', 'invalid_message' => WholeNumber::NOT_A_NUMBER]);
        if ($options['import']) {
            $builder->add('file', FileType::class, ['label' => 'File', 'help' => 'A text file of email addresses, separated by commas, spaces or new lines.', 'attr' => ['accept' => '.txt,.csv,text/plain,text/csv']]);
        } else {
            $builder->add('emails', TextareaType::class, ['label' => 'Email addresses', 'empty_data' => '', 'attr' => ['rows' => 12, 'class' => 'font-monospace'],
                'help' => 'Separate addresses with commas, spaces or new lines. Anything that is not a usable address is ignored.']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BulkSubscription::class,
            'import' => false,
            'validation_groups' => static fn($form): array => ['Default', $form->getConfig()->getOption('import') ? 'file' : 'paste'],
        ]);
        $resolver->setRequired('lists');
        $resolver->setAllowedTypes('lists', 'array');
        $resolver->setAllowedTypes('import', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'bulk_subscribe';
    }
}
