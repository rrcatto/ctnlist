<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailAddressList;
use App\Validator\WholeNumber;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bulk subscribe and import (BulkSubscribeType): addresses to add to a list
 * as pending members, each sent an invitation. Addresses come pasted or
 * from an uploaded text file.
 */
final class BulkSubscription
{
    #[Assert\NotNull(message: 'Choose a list.')]
    public ?int $listId = null;

    #[WholeNumber(min: -2147483648, max: 2147483647)]
    public int $priority = 0;

    #[Assert\NotBlank(message: 'Enter at least one email address.', groups: ['paste'])]
    #[Assert\Length(max: 10000000, groups: ['paste'])]
    #[EmailAddressList(groups: ['paste'])]
    public string $emails = '';

    #[Assert\NotNull(message: 'Choose a file to import.', groups: ['file'])]
    #[Assert\File(maxSize: '20M', extensions: ['txt', 'csv'], extensionsMessage: 'Upload a .txt or .csv file of email addresses.', groups: ['file'])]
    public ?UploadedFile $file = null;
}
