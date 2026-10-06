<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\EmailAddress;
use Symfony\Component\Validator\Constraints as Assert;

/** The contact/order form (ContactType), handed to ContactService. */
final class ContactRequest
{
    #[Assert\NotBlank(message: 'Enter your name.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Enter your email address so we can reply.')]
    #[EmailAddress]
    public string $email = '';

    #[Assert\Length(max: 50)]
    public string $cell = '';

    #[Assert\Length(max: 100)]
    public string $company = '';

    /** Free text, as in v5 (people write "example.com"). */
    #[Assert\Length(max: 200)]
    public string $website = '';

    #[Assert\Length(max: 200)]
    public string $topic = '';

    #[Assert\NotBlank(message: 'Enter your message.')]
    #[Assert\Length(max: 10000)]
    public string $message = '';

    /** The subscriber and message of a {contact}/{booking} link, if any (hidden fields: empty is null). */
    public ?string $suid = null;
    public ?string $muid = null;

    /** @return array<string, string> the fields ContactService::submit() takes */
    public function fields(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'cell' => $this->cell, 'website' => $this->website,
            'company' => $this->company, 'topic' => $this->topic, 'message' => $this->message];
    }
}
