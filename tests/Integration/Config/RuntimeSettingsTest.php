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

/** Settings resolution (database → .env → default), storage in `options`, secrets and validation. */
final class RuntimeSettingsTest extends IntegrationTestCase
{
    private const SMTP = ['smtp_scheme' => 'smtps', 'smtp_host' => 'mail.example.net', 'smtp_port' => '465', 'smtp_username' => 'list@example.net',
        'smtp_password' => 'p@ss:w/rd', 'smtp_options' => '', 'MAIL_RATE_PER_MINUTE' => '30', 'MAIL_BATCH_SIZE' => '100', 'MAIL_BATCH_DELAY' => '5', 'MAIL_BOUNCE_LIMIT' => '2'];

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
        $settings->save('smtp', self::SMTP + $this->serversInput(), null);

        $dsn = 'smtps://list%40example.net:p%40ss%3Aw%2Frd@mail.example.net:465';
        self::assertSame($dsn, $settings->get('MAILER_DSN'));
        $stored = $this->db->fetchAssociative("SELECT o_value, o_secret FROM options WHERE o_key = 'setting:MAILER_DSN'") ?: [];
        self::assertTrue(SettingsCipher::isEncrypted((string) $stored['o_value']));
        self::assertTrue((bool) $stored['o_secret']);
        self::assertStringNotContainsString('rd@mail', (string) $stored['o_value'], 'not stored as plaintext');
        self::assertNotSame($dsn, $settings->environment()['MAILER_DSN'] ?? null, 'secret overrides are not part of environment()');

        $pool = ($this->service(SmtpServerPoolFactory::class))();
        self::assertSame($dsn, $pool->transactionalServer()?->dsn, 'mail uses the decrypted override');

        $settings->save('smtp', ['smtp_password' => ''] + self::SMTP + $this->serversInput(), null);
        self::assertSame($dsn, $this->fresh()->get('MAILER_DSN'), 'an empty password keeps the stored one');

        $settings->save('smtp', ['smtp_password' => 'n3w'] + self::SMTP + $this->serversInput(), null);
        self::assertSame('smtps://list%40example.net:n3w@mail.example.net:465', $this->fresh()->get('MAILER_DSN'), 'a new password replaces it');

        $settings->resetSetting('MAILER_DSN', null);
        self::assertSame($settings->envValue('MAILER_DSN'), $this->fresh()->get('MAILER_DSN'), 'reset removes the override');
    }

    /** Failover servers keep their structure, order and passwords. */
    public function testFailoverServersAreEditedAsServers(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        $settings->save('smtp', self::SMTP + ['servers' => [
            ['index' => '', 'position' => '2', 'active' => '1', 'host' => 'second.example.net', 'port' => '587', 'security' => 'tls', 'username' => 'u2', 'password' => 'pw2', 'batchsize' => '50', 'delay' => '3', 'sendrate' => ''],
            ['index' => '', 'position' => '1', 'active' => '1', 'host' => 'first.example.net', 'port' => '465', 'security' => 'ssl', 'username' => 'u1', 'password' => 'pw1', 'batchsize' => '100', 'delay' => '0', 'sendrate' => '60'],
            ['index' => '', 'position' => '3', 'active' => '', 'host' => '', 'port' => '', 'security' => 'tls', 'username' => '', 'password' => '', 'batchsize' => '', 'delay' => '', 'sendrate' => ''],
        ]], null);

        $rows = RuntimeSettings::serverRows($settings->get('MAIL_SMTP_SERVERS_JSON'));
        self::assertSame(['first.example.net', 'second.example.net'], array_column($rows, 'host'), 'failover order');
        self::assertSame([true, true], array_column($rows, 'has_password'));
        self::assertArrayNotHasKey('password', $rows[0], 'rows never carry passwords');

        $servers = ($this->service(SmtpServerPoolFactory::class))()->campaignServers();
        self::assertSame(['smtps://u1:pw1@first.example.net:465', 'smtp://u2:pw2@second.example.net:587?require_tls=true'], array_map(static fn($s): string => $s->dsn, $servers));
        self::assertSame([100, 50], array_map(static fn($s): int => $s->batchSize, $servers));

        // Edit: keep passwords (empty), move second to the front, deactivate first.
        $settings->save('smtp', self::SMTP + ['servers' => [
            ['index' => '0', 'position' => '2', 'active' => '', 'host' => 'first.example.net', 'port' => '465', 'security' => 'ssl', 'username' => 'u1', 'password' => '', 'batchsize' => '100', 'delay' => '0', 'sendrate' => '60'],
            ['index' => '1', 'position' => '1', 'active' => '1', 'host' => 'second.example.net', 'port' => '587', 'security' => 'tls', 'username' => 'u2', 'password' => '', 'batchsize' => '50', 'delay' => '3', 'sendrate' => ''],
        ]], null);
        $servers = ($this->service(SmtpServerPoolFactory::class))()->campaignServers();
        self::assertSame(['smtp://u2:pw2@second.example.net:587?require_tls=true'], array_map(static fn($s): string => $s->dsn, $servers), 'only active servers, new order, passwords kept');

        $settings->save('smtp', self::SMTP + ['servers' => [['index' => '0', 'host' => 'first.example.net', 'remove' => '1'], ['index' => '1', 'host' => 'second.example.net', 'remove' => '1']]], null);
        self::assertSame([], RuntimeSettings::serverRows($this->fresh()->get('MAIL_SMTP_SERVERS_JSON')));
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

    public function testInvalidValuesAreRejectedWithoutSavingTheGroup(): void
    {
        $settings = $this->service(RuntimeSettings::class);
        foreach ([
            ['sender', ['MAIL_FROM_ADDRESS' => 'not-an-address'], 'valid email'],
            ['sender', ['MAIL_TEST_ADDRESS' => 'x@'], 'valid email'],
            ['contact', ['APP_FACEBOOK_URL' => 'javascript:alert(1)'], 'full web address'],
            ['signin', ['AUTH_MAGIC_LINK_TTL' => '10'], 'at least 60'],
            ['smtp', ['smtp_host' => 'bad host/name'] + self::SMTP, 'valid host name'],
            ['smtp', ['smtp_port' => '70000'] + self::SMTP, 'between 1 and 65535'],
            ['smtp', self::SMTP + ['servers' => [['host' => 'relay.example.net', 'port' => '0']]], 'between 1 and 65535'],
        ] as [$group, $changes, $message]) {
            try {
                $settings->save($group, $this->shownInput($group, $changes), null);
                self::fail('accepted ' . json_encode($changes));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->db->fetchFirstColumn("SELECT o_key FROM options WHERE o_key LIKE 'setting:%'"), 'nothing stored');
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
            } elseif ($setting['type'] === 'servers') {
                $input += $this->serversInput();
            } else {
                $input[$name] = $shown;
            }
        }
        return $changes + $input;
    }

    /** @return array{servers: list<array<string, string>>} the failover rows as shown */
    private function serversInput(): array
    {
        $rows = RuntimeSettings::serverRows($this->service(RuntimeSettings::class)->get('MAIL_SMTP_SERVERS_JSON', '[]'));
        return ['servers' => array_map(static fn(array $row): array => [
            'index' => $row['index'], 'position' => (string) ((int) $row['index'] + 1), 'active' => $row['active'] ? '1' : '',
            'host' => $row['host'], 'port' => $row['port'], 'security' => $row['security'], 'username' => $row['username'],
            'password' => '', 'batchsize' => $row['batchsize'], 'delay' => $row['delay'], 'sendrate' => $row['sendrate'],
        ], $rows)];
    }

    /** @param array<string, mixed> $setting */
    private static function shown(RuntimeSettings $settings, string $name, array $setting): string
    {
        return in_array($setting['type'] ?? '', ['int', 'bool', 'servers'], true) ? $settings->get($name, (string) ($setting['default'] ?? '')) : $settings->get($name);
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
