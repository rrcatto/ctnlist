<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailAddress;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The public subscribe form (SubscribeType): an address and the list to
 * confirm. Nothing is subscribed until the emailed link is confirmed.
 */
final class SubscribeRequest
{
    #[Assert\NotBlank(message: 'Enter your email address.')]
    #[EmailAddress]
    public string $email = '';

    #[Assert\NotNull(message: 'Choose a list.')]
    public ?int $listId = null;

    /** The id of the message the visitor came from (/subscribe?m=…), if any; a hidden field. */
    public ?string $messageId = null;
}
