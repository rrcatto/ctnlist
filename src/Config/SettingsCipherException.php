<?php

declare(strict_types=1);

namespace App\Config;

/** APP_SETTINGS_KEY is missing or malformed, or a stored secret cannot be decrypted. Never carries secret values. */
final class SettingsCipherException extends \RuntimeException
{
}
