<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** A subscriber's own profile (ProfileType); the address is the identity and is not edited. */
final class ProfileDetails
{
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\Length(max: 100)]
    public string $lastName = '';

    #[Assert\Length(max: 30)]
    public string $phone = '';

    #[Assert\LessThanOrEqual('today', message: 'The birthdate cannot be in the future.')]
    #[Assert\GreaterThan('1900-01-01', message: 'Enter a birthdate after 1900.')]
    public ?\DateTimeImmutable $birthday = null;

    #[Assert\Length(max: 30)]
    public ?string $gender = null;

    #[Assert\Length(max: 100)]
    public ?string $province = null;

    #[Assert\Length(max: 100)]
    public ?string $country = null;

    #[Assert\Length(max: 100)]
    public string $business = '';

    /** Free text, as in v5. */
    #[Assert\Length(max: 253)]
    public string $url = '';

    /** @param array<string, mixed> $profile a SubscriberRepository::profile() row */
    public static function fromProfile(array $profile): self
    {
        $details = new self();
        $details->firstName = (string) ($profile['s_fname'] ?? '');
        $details->lastName = (string) ($profile['s_lname'] ?? '');
        $details->phone = (string) ($profile['s_phone'] ?? '');
        $birthday = (string) ($profile['s_birthday'] ?? '');
        $details->birthday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) === 1 ? (new \DateTimeImmutable($birthday)) : null;
        $details->gender = (string) ($profile['s_gender'] ?? '') ?: null;
        $details->province = (string) ($profile['s_province'] ?? '') ?: null;
        $details->country = (string) ($profile['s_country'] ?? '') ?: null;
        $details->business = (string) ($profile['s_business'] ?? '');
        $details->url = (string) ($profile['s_url'] ?? '');
        return $details;
    }

    /** @return array<string, string> ProfileService::update() input */
    public function input(): array
    {
        return [
            's_fname' => $this->firstName,
            's_lname' => $this->lastName,
            's_phone' => $this->phone,
            's_birthday' => $this->birthday?->format('Y-m-d') ?? '',
            's_gender' => (string) $this->gender,
            's_province' => (string) $this->province,
            's_country' => (string) $this->country,
            's_business' => $this->business,
            's_url' => $this->url,
        ];
    }
}
