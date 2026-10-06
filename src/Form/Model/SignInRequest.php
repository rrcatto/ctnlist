<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailAddress;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A request for a sign-in link (SignInType). Only the address's format is
 * checked here; whether it may sign in stays hidden behind the "if the
 * address is valid" answer (MagicLinkRequester).
 */
final class SignInRequest
{
    #[Assert\NotBlank(message: 'Enter your email address.')]
    #[EmailAddress]
    public string $email = '';
}
