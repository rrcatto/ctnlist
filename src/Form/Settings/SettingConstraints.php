<?php

declare(strict_types=1);

namespace App\Form\Settings;

use App\Validator\EmailAddress;
use App\Validator\HostName;
use App\Validator\Port;
use App\Validator\WebAddress;
use App\Validator\WholeNumber;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input rules for the Settings page, derived from the SettingsCatalogue
 * entry, built from the application's shared constraints (App\Validator).
 *
 * @phpstan-import-type Setting from \App\Config\SettingsCatalogue
 */
final class SettingConstraints
{
    /** Shown when a number field does not hold a whole number (the field's invalid_message). */
    public const NOT_A_NUMBER = WholeNumber::NOT_A_NUMBER;

    /**
     * @param Setting $setting
     * @return list<Constraint>
     */
    public static function for(array $setting): array
    {
        $constraints = self::required($setting) ? [new Assert\NotBlank(message: $setting['label'] . ' is required.')] : [];
        // One-line values end up in mail headers (sender name, subjects) and links: no line breaks.
        $singleLine = new Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'Enter this on one line.');
        return [...$constraints, ...match ($setting['type']) {
            'email' => [new EmailAddress()],
            'url' => [new WebAddress()],
            'int' => [new WholeNumber(min: $setting['min'] ?? null)],
            'text' => [new Assert\Length(max: 2000), $singleLine],
            'textarea' => [new Assert\Length(max: 2000)],
            // A link template that becomes a clickable link in mail: only web addresses (never javascript: or data:).
            'link' => [new Assert\Length(max: 2000), $singleLine, new Assert\Regex(pattern: '#^(https?://|\{BaseURL\})#i', message: 'Start the link with https://, http:// or {BaseURL}.')],
            default => [],
        }];
    }

    /**
     * A setting must have a value unless it is optional or has a default.
     *
     * @param Setting $setting
     */
    public static function required(array $setting): bool
    {
        return !($setting['optional'] ?? false) && !isset($setting['default']) && !in_array($setting['type'], ['bool', 'dsn'], true);
    }

    /** @return list<Constraint> */
    public static function host(): array
    {
        return [new HostName()];
    }

    /** @return list<Constraint> */
    public static function port(): array
    {
        return [new Port()];
    }
}
