<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Subscriber\ProfileImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The profile picture form: the chosen file and, when the browser's editor
 * ran, the square it cropped (a PNG data URL). ProfileImage decides whether
 * either is a picture; these constraints only give early, friendly answers.
 */
final class ProfileImageUpload
{
    #[Assert\File(maxSize: ProfileImage::MAX_UPLOAD_BYTES, maxSizeMessage: 'That picture is larger than 8 MB. Choose a smaller file.')]
    public ?UploadedFile $image = null;

    #[Assert\Length(max: ProfileImage::MAX_EDITED_FIELD_BYTES, maxMessage: 'The edited picture was too large. Choose a smaller one.')]
    public ?string $edited = null;

    #[Assert\Callback]
    public function validateChoice(ExecutionContextInterface $context): void
    {
        if ($this->image === null && $this->editedImage() === '') {
            $context->buildViolation('Choose a picture first.')->atPath('image')->addViolation();
        }
    }

    /** The editor's PNG data URL, or '' when the browser did not edit (no JavaScript, or nothing chosen there). */
    public function editedImage(): string
    {
        return (string) $this->edited;
    }

    /** The uploaded file's bytes, or '' when there is none. */
    public function uploadedBytes(): string
    {
        if ($this->image === null || !$this->image->isValid()) {
            return '';
        }
        return (string) file_get_contents($this->image->getPathname());
    }
}
