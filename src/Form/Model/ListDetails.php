<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\ListShortcode;
use Symfony\Component\Validator\Constraints as Assert;

/** A topic list's details (ListType). The shortcode is set once, on creation. */
final class ListDetails
{
    #[Assert\NotBlank(message: 'Enter a shortcode.', groups: ['create'])]
    #[ListShortcode(groups: ['create'])]
    public string $shortcode = '';

    #[Assert\NotBlank(message: 'Enter a name.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    #[Assert\Length(max: 2000)]
    public string $description = '';

    /** @param array{l_shortcode: string, l_name: string, l_description: string|null} $list */
    public static function fromList(array $list): self
    {
        $details = new self();
        $details->shortcode = $list['l_shortcode'];
        $details->name = $list['l_name'];
        $details->description = (string) $list['l_description'];
        return $details;
    }
}
