<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\RoleKey;
use Symfony\Component\Validator\Constraints as Assert;

/** A custom role's details (RoleType). The key is set once, on creation. */
final class RoleDetails
{
    #[Assert\NotBlank(message: 'Enter a key.', groups: ['create'])]
    #[RoleKey(groups: ['create'])]
    public string $key = '';

    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Length(max: 500)]
    public string $description = '';

    /** @param array{r_key: string, r_name: string, r_description: string} $role */
    public static function fromRole(array $role): self
    {
        $details = new self();
        $details->key = $role['r_key'];
        $details->name = $role['r_name'];
        $details->description = $role['r_description'];
        return $details;
    }
}
