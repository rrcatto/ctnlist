<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Form\Model\ProfileImageUpload;
use App\Subscriber\ProfileImage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Data: ProfileImageUpload. `edited` is filled by assets/profile_image.js. */
final class ProfileImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('image', FileType::class, [
                'label' => 'Choose a picture',
                'required' => false,
                'help' => ProfileImage::FORMATS . ', up to 8 MB. After choosing, drag to position it, use the slider to zoom and the buttons to rotate.',
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp', 'data-profile-image-file' => true],
            ])
            ->add('edited', HiddenType::class, ['attr' => ['data-profile-image-data' => true]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileImageUpload::class,
            'upload_max_size_message' => static fn(): string => 'That picture is too large to upload. Choose a smaller file.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'profile_image';
    }
}
