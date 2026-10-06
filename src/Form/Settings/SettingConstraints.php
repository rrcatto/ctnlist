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
        return [...$constraints, ...match ($setting['type']) {
            'email' => [new EmailAddress()],
            'url' => [new WebAddress()],
            'int' => [new WholeNumber(min: $setting['min'] ?? null)],
            'text', 'textarea' => [new Assert\Length(max: 2000)],
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
