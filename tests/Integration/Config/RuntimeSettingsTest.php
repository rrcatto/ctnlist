<?php

declare(strict_types=1);

namespace App\Tests\Integration\Config;

use App\Config\RuntimeSettings;
use App\Config\SettingsCatalogue;
use App\Config\SettingsCipher;
use App\Config\SettingsCipherException;
use App\Config\SiteConfigFactory;
use App\Mail\SmtpServerPoolFactory;
use App\Repository\SettingRepository;
use App\Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;

/** Settings resolution (database → .env → default), storage in `options` and secrets (input rules: SettingsFormTest). */
final class RuntimeSettingsTest extends IntegrationTestCase
{
    private const SMTP = ['smtp_scheme' => 'smtps', 'smtp_host' => 'mail.example.net', 'smtp_port' => '465', 'smtp_username' => 'list@example.net',
        'smtp_password' => 'p@ss:w/rd', 'smtp_options' => '', 'MAIL_RATE_PER_MINUTE' => '30'];

    public function testDatabaseOverridesEnvWhichOverridesTheDefault(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        $env = $settings->envValue('APP_LIST_NAME');
        self::assertNotSame('', $env, 'the test installation sets APP_LIST_NAME');
        self::assertSame(RuntimeSettings::SOURCE_ENV, $settings->source('APP_LIST_NAME'));

        $settings->save('site', $this->shownInput('site', ['APP_LIST_NAME' => 'Overridden list']), null);
        self::assertSame(RuntimeSettings::SOURCE_DATABASE, $settings->source('APP_LIST_NAME'));
        self::assertSame('Overridden list', $settings->get('APP_LIST_NAME'));
        self::assertSame('Overridden list', ($this->service(SiteConfigFactory::class))()->listName, 'SiteConfig resolves through the settings layer');
        self::assertSame('setting:APP_LIST_NAME', $this->db->fetchOne("SELECT o_key FROM options WHERE o_key LIKE 'setting:%'"), 'stored in options, nothing else');

        $settings->resetSetting('APP_LIST_NAME', null);
        self::assertSame($env, $settings->get('APP_LIST_NAME'), 'reset exposes .env');

        // A setting .env leaves unset falls through to its default.
        $unset = $this->unsetIntSetting();
        $default = $this->definition($unset)['default'] ?? '';
        self::assertSame(RuntimeSettings::SOURCE_DEFAULT, $settings->source($unset));
        self::assertSame($default, $settings->get($unset, $default));
        $group = SettingsCatalogue::names()[$unset];
        $settings->save($group, $this->shownInput($group, [$unset => (string) ((int) $default + 7)]), null);
        self::assertSame(RuntimeSettings::SOURCE_DATABASE, $settings->source($unset));
        $settings->resetGroup($group, null);
        self::assertSame(RuntimeSettings::SOURCE_DEFAULT, $settings->source($unset), 'reset exposes the default');
    }

    /** Submitting every section exactly as the page shows it stores nothing: the database holds overrides only. */
    public function testSavingUnchangedSectionsStoresNothing(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        foreach (array_keys(SettingsCatalogue::GROUPS) as $group) {
            self::assertSame([], $settings->save($group, $this->shownInput($group), null), $group);
        }
        self::assertSame([], $this->db->fetchFirstColumn("SELECT o_key FROM options WHERE o_key LIKE 'setting:%'"));
    }

    public function testSmtpSecretsAreEncryptedAndResolveIntoTheMailConfiguration(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        $settings->save('smtp', self::SMTP, null);

        $dsn = 'smtps://list%40example.net:p%40ss%3Aw%2Frd@mail.example.net:465';
        self::assertSame($dsn, $settings->get('MAILER_DSN'));
        $stored = $this->db->fetchAssociative("SELECT o_value, o_secret FROM options WHERE o_key = 'setting:MAILER_DSN'") ?: [];
        self::assertTrue(SettingsCipher::isEncrypted((string) $stored['o_value']));
        self::assertTrue((bool) $stored['o_secret']);
        self::assertStringNotContainsString('rd@mail', (string) $stored['o_value'], 'not stored as plaintext');
        self::assertNotSame($dsn, $settings->environment()['MAILER_DSN'] ?? null, 'secret overrides are not part of environment()');

        $pool = ($this->service(SmtpServerPoolFactory::class))();
        self::assertSame($dsn, $pool->transactionalServer()?->dsn, 'mail uses the decrypted override');

        $settings->save('smtp', ['smtp_password' => ''] + self::SMTP, null);
        self::assertSame($dsn, $this->fresh()->get('MAILER_DSN'), 'an empty password keeps the stored one');

        $settings->save('smtp', ['smtp_password' => 'n3w'] + self::SMTP, null);
        self::assertSame('smtps://list%40example.net:n3w@mail.example.net:465', $this->fresh()->get('MAILER_DSN'), 'a new password replaces it');

        $settings->resetSetting('MAILER_DSN', null);
        self::assertSame($settings->envValue('MAILER_DSN'), $this->fresh()->get('MAILER_DSN'), 'reset removes the override');
    }

    /** A secret that cannot be decrypted fails loudly; it never silently becomes empty or falls back. */
    public function testUndecryptableSecretsFailLoudly(): void
    {
        $this->service(SettingRepository::class)->set('MAILER_DSN', (new SettingsCipher(base64_encode(random_bytes(32))))->encrypt('smtp://x:y@other.example.net'), true, null);
        $settings = $this->fresh();

        self::assertStringContainsString('different APP_SETTINGS_KEY', (string) $settings->secretProblem('MAILER_DSN'));
        self::assertSame(RuntimeSettings::SOURCE_DATABASE, $settings->source('MAILER_DSN'));
        self::assertArrayHasKey('APP_LIST_NAME', $settings->environment(), 'the rest of the site keeps working');
        $this->expectException(SettingsCipherException::class);
        $settings->get('MAILER_DSN');
    }

    /**
     * A group as the Settings page submits it unchanged, plus $changes.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function shownInput(string $group, array $changes = []): array
    {
        $settings = $this->service(RuntimeSettings::class);
        $input = [];
        foreach (SettingsCatalogue::GROUPS[$group]['settings'] as $name => $setting) {
            $shown = self::shown($settings, $name, $setting);
            if ($setting['type'] === 'dsn') {
                $parts = RuntimeSettings::dsnParts($shown);
                $input += ['smtp_scheme' => $parts['scheme'], 'smtp_host' => $parts['host'], 'smtp_port' => $parts['port'],
                    'smtp_username' => $parts['username'], 'smtp_password' => '', 'smtp_options' => $parts['options']];
            } else {
                $input[$name] = $shown;
            }
        }
        return $changes + $input;
    }

    /** @param array<string, mixed> $setting */
    private static function shown(RuntimeSettings $settings, string $name, array $setting): string
    {
        return in_array($setting['type'] ?? '', ['int', 'bool'], true) ? $settings->get($name, (string) ($setting['default'] ?? '')) : $settings->get($name);
    }

    /** @return array<string, mixed> */
    private function definition(string $name): array
    {
        return SettingsCatalogue::GROUPS[SettingsCatalogue::names()[$name]]['settings'][$name];
    }

    private function unsetIntSetting(): string
    {
        $settings = $this->service(RuntimeSettings::class);
        foreach (SettingsCatalogue::names() as $name => $group) {
            if (($this->definition($name)['type'] ?? '') === 'int' && $settings->envValue($name) === '') {
                return $name;
            }
        }
        self::markTestSkipped('every number setting is set in this installation’s .env');
    }

    /** A new RuntimeSettings (no per-request cache) reading the same database. */
    private function fresh(): RuntimeSettings
    {
        return new RuntimeSettings($this->service(SettingRepository::class), $this->service(SettingsCipher::class), new NullLogger());
    }
}
