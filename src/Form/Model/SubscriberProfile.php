<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Validator\WholeNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The subscriber form (SubscriberType): profile fields, plus priority and a
 * list to invite them to for administrators. The address is the identity
 * and is not edited here; memberships are confirmed only by the subscriber.
 */
final class SubscriberProfile
{
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\Length(max: 100)]
    public string $lastName = '';

    /** Choices (null when none is chosen). */
    #[Assert\Length(max: 30)]
    public ?string $gender = null;

    #[Assert\Length(max: 100)]
    public ?string $province = null;

    #[Assert\Length(max: 100)]
    public ?string $country = null;

    /** Saving sets the priority to this + 100 (v5 engagement rule); the column is an INTEGER. */
    #[WholeNumber(min: -2147483648, max: 2147483547)]
    public int $priority = 0;

    /** A list to add a pending membership to (and invite them), or none. */
    public ?int $listId = null;

    /**
     * @param array{s_fname: string, s_lname: string, s_gender: string, s_province: string, s_country: string} $profile
     */
    public static function fromProfile(array $profile, int $priority): self
    {
        $data = new self();
        $data->firstName = $profile['s_fname'];
        $data->lastName = $profile['s_lname'];
        $data->gender = $profile['s_gender'];
        $data->province = $profile['s_province'];
        $data->country = $profile['s_country'];
        $data->priority = $priority;
        return $data;
    }

    /** @return array<string, string> SubscriberAdmin::save() input */
    public function input(): array
    {
        return [
            's_fname' => $this->firstName,
            's_lname' => $this->lastName,
            's_gender' => (string) $this->gender,
            's_province' => (string) $this->province,
            's_country' => (string) $this->country,
            's_priority' => (string) $this->priority,
            'list_id' => (string) ($this->listId ?? 0),
        ];
    }
}
