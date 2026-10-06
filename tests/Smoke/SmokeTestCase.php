<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that drive the running podman stack over HTTP (from the app
 * container to SMOKE_BASE_URL) and inspect the development database. They
 * exercise the web server, not the framework.
 */
abstract class SmokeTestCase extends TestCase
{
    protected static PDO $db;
    protected static string $baseUrl;
    /** Start of the test class, in the application's (PHP) timezone. */
    protected static string $startedAt;
    /** @var array{s_id: int, s_uuid: string, s_email: string} */
    protected static array $admin;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim((string) getenv('SMOKE_BASE_URL'), '/');
        self::$db = new PDO(
            (string) getenv('SMOKE_DB_DSN'),
            (string) getenv('SMOKE_DB_USER'),
            (string) getenv('SMOKE_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // The application writes timestamps in PHP's timezone, not the database's.
        self::$startedAt = date('Y-m-d H:i:s');

        $admin = self::$db->prepare('SELECT s_id, s_uuid, s_email FROM subscribers WHERE LOWER(s_email) = LOWER(?)');
        $admin->execute([(string) getenv('SMOKE_ADMIN_EMAIL')]);
        $row = $admin->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            self::fail('Development administrator not found; run bin/dev seed-admin.');
        }
        self::$admin = ['s_id' => (int) $row['s_id'], 's_uuid' => (string) $row['s_uuid'], 's_email' => (string) $row['s_email']];
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ? AND as_created_at >= ?')
            ->execute([self::$admin['s_id'], self::$startedAt]);
        self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ? AND alt_created_at >= ?')
            ->execute([self::$admin['s_id'], self::$startedAt]);
        self::assertSystemDataIntact(static::class);
    }

    /**
     * Smoke tests share the development database: after every class the
     * system data the application relies on must be untouched, so a class
     * that damages it fails where it happened.
     */
    private static function assertSystemDataIntact(string $class): void
    {
        $system = self::rows("SELECT r_key, r_system FROM roles WHERE r_key IN ('administrator', 'subscriber') ORDER BY r_key");
        self::assertSame([['r_key' => 'administrator', 'r_system' => true], ['r_key' => 'subscriber', 'r_system' => true]], $system, "{$class}: system roles");
        self::assertSame(1, (int) self::value("SELECT COUNT(*) FROM lists WHERE l_shortcode = 'ALL' AND l_system"), "{$class}: the ALL list");
        self::assertSame(0, (int) self::value(
            "SELECT COUNT(*) FROM subscribers s WHERE NOT EXISTS (SELECT 1 FROM subscriber_roles sr JOIN roles r ON r.r_id = sr.sr_r_id WHERE sr.sr_s_id = s.s_id AND r.r_key = 'subscriber')"
        ), "{$class}: every subscriber holds the subscriber role");
        self::assertSame(1, (int) self::value(
            "SELECT COUNT(*) FROM subscriber_roles sr JOIN roles r ON r.r_id = sr.sr_r_id WHERE sr.sr_s_id = ? AND r.r_key = 'administrator'", [self::$admin['s_id']]
        ), "{$class}: the development administrator");
        self::assertSame(0, (int) self::value(
            "SELECT COUNT(*) FROM acl_permissions ap WHERE NOT EXISTS (SELECT 1 FROM role_permissions rp JOIN roles r ON r.r_id = rp.rp_r_id WHERE rp.rp_ap_id = ap.ap_id AND r.r_key = 'administrator')"
        ), "{$class}: the administrator role holds every permission");
    }

    /** A browser-like client: one curl handle with its own cookie jar. */
    protected static function client(): \CurlHandle
    {
        $client = curl_init();
        curl_setopt($client, CURLOPT_COOKIEFILE, '');
        return $client;
    }

    /** Store a magic-link token directly (as POST /login would) and return the raw token. */
    protected static function issueLoginToken(int $subscriberId, string $returnAction = 'profile'): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::$db->prepare(
            'INSERT INTO auth_login_tokens (alt_s_id, alt_email, alt_token_hash, alt_created_at, alt_expires_at, alt_return_action)
             SELECT s_id, s_email, ?, ?, ?, ? FROM subscribers WHERE s_id = ?'
        )->execute([hash('sha256', $token), date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 600), $returnAction, $subscriberId]);
        return $token;
    }

    /** Sign the client in as the development administrator. */
    protected static function loginAsAdmin(\CurlHandle $client): void
    {
        $response = self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken(self::$admin['s_id']));
        if ($response['status'] !== 302 || str_ends_with($response['location'], '/login')) {
            self::fail("Administrator magic-link login failed (HTTP {$response['status']}, Location {$response['location']}).");
        }
    }

    /**
     * @param array<string, string|\CURLFile> $fields form fields for POST; with a file it is sent as multipart/form-data
     * @return array{status: int, body: string, location: string, cookies: list<string>}
     */
    protected static function request(\CurlHandle $client, string $method, string $path, array $fields = []): array
    {
        $multipart = array_filter($fields, static fn($value): bool => $value instanceof \CURLFile) !== [];
        $location = '';
        $cookies = [];
        curl_setopt_array($client, [
            CURLOPT_URL => self::$baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? ($multipart ? $fields : http_build_query($fields)) : null,
            CURLOPT_HTTPGET => $method === 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location, &$cookies): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                } elseif (stripos($header, 'Set-Cookie:') === 0) {
                    $cookies[] = trim(substr($header, 11));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        if ($body === false) {
            self::fail("{$method} {$path} failed: " . curl_error($client));
        }
        return [
            'status' => (int) curl_getinfo($client, CURLINFO_HTTP_CODE),
            'body' => (string) $body,
            'location' => $location,
            'cookies' => $cookies,
        ];
    }

    /**
     * The fields a browser submits for the form named $form in $html: enabled
     * inputs (checkboxes and radios only when checked), selected options and
     * textareas. $overrides sets fields by their full name; null removes one
     * (an unticked box). Repeated "name[]" fields are given as a list, and an
     * override of "name[]" replaces the whole list.
     *
     * @param array<string, string|list<string>|null> $overrides
     * @return array<string, string>
     */
    protected static function formFields(string $html, string $form, array $overrides = []): array
    {
        $element = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('form[name="' . $form . '"]');
        self::assertNotNull($element, "form {$form} on the page");
        $fields = [];
        $lists = [];
        foreach ($element->querySelectorAll('input[name], select[name], textarea[name]') as $field) {
            if ($field->hasAttribute('disabled') || $field->closest('fieldset[disabled]') !== null) {
                continue;
            }
            $name = (string) $field->getAttribute('name');
            $type = strtolower((string) $field->getAttribute('type'));
            if ($field->localName === 'select') {
                $selected = $field->querySelector('option[selected]') ?? $field->querySelector('option');
                $value = $selected === null ? '' : (string) ($selected->getAttribute('value') ?? $selected->textContent);
            } elseif ($field->localName === 'textarea') {
                $value = (string) $field->textContent;
            } elseif (in_array($type, ['checkbox', 'radio'], true)) {
                if (!$field->hasAttribute('checked')) {
                    continue;
                }
                $value = (string) ($field->getAttribute('value') ?? 'on');
            } elseif (in_array($type, ['submit', 'button', 'reset', 'file'], true)) {
                continue;
            } else {
                $value = (string) $field->getAttribute('value');
            }
            if (str_ends_with($name, '[]')) {
                $lists[$name][] = $value;
            } else {
                $fields[$name] = $value;
            }
        }
        foreach ($overrides as $name => $value) {
            unset($fields[$name], $lists[$name]);
            if (is_array($value)) {
                $lists[$name] = $value;
            } elseif ($value !== null) {
                $fields[$name] = $value;
            }
        }
        foreach ($lists as $name => $values) {
            foreach ($values as $n => $item) {
                $fields[substr($name, 0, -2) . '[' . $n . ']'] = $item;
            }
        }
        return $fields;
    }

    /**
     * Load $page and submit its form $form (to the form's action) with
     * $overrides, as a browser would.
     *
     * @param array<string, string|list<string>|null> $overrides
     * @return array{status: int, body: string, location: string, cookies: list<string>}
     */
    protected static function submitForm(\CurlHandle $client, string $page, string $form, array $overrides = []): array
    {
        $html = self::request($client, 'GET', $page)['body'];
        $element = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('form[name="' . $form . '"]');
        self::assertNotNull($element, "form {$form} on {$page}");
        $action = (string) ($element->getAttribute('action') ?: $page);
        return self::request($client, 'POST', $action, self::formFields($html, $form, $overrides));
    }

    /**
     * submitForm() expecting success (post/redirect/get): returns the page
     * redirected to, or fails with the form's errors.
     *
     * @param array<string, string|list<string>|null> $overrides
     */
    protected static function submitAndFollow(\CurlHandle $client, string $page, string $form, array $overrides = []): string
    {
        $response = self::submitForm($client, $page, $form, $overrides);
        $errors = preg_match_all('#class="invalid-feedback[^>]*>([^<]+)#', $response['body'], $m) ? implode('; ', $m[1]) : '';
        self::assertSame(302, $response['status'], "submit {$form} on {$page}: {$errors}");
        $location = (string) parse_url($response['location'], PHP_URL_PATH);
        $query = parse_url($response['location'], PHP_URL_QUERY);
        $next = self::request($client, 'GET', $location . (is_string($query) ? '?' . $query : ''))['body'];
        self::assertCleanPage($location, $next);
        return $next;
    }

    /** Whether public archives are on: the Settings override, else the installation's .env. */
    protected static function archivesEnabled(): bool
    {
        $override = self::value("SELECT o_value FROM options WHERE o_key = 'setting:APP_ARCHIVE_ENABLED'");
        return filter_var($override !== false ? $override : ($_ENV['APP_ARCHIVE_ENABLED'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Run $test with a Settings override in place, then put back whatever the
     * installation had (its own override, or none).
     */
    protected static function withSetting(string $name, string $value, \Closure $test): void
    {
        $key = 'setting:' . $name;
        $saved = self::rows('SELECT o_value, o_secret, o_updated_at, o_updated_by_s_id FROM options WHERE o_key = ?', [$key]);
        self::$db->prepare('DELETE FROM options WHERE o_key = ?')->execute([$key]);
        self::$db->prepare('INSERT INTO options (o_key, o_value) VALUES (?, ?)')->execute([$key, $value]);
        try {
            $test();
        } finally {
            self::$db->prepare('DELETE FROM options WHERE o_key = ?')->execute([$key]);
            foreach ($saved as $row) {
                self::$db->prepare('INSERT INTO options (o_key, o_value, o_secret, o_updated_at, o_updated_by_s_id) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$key, $row['o_value'], $row['o_secret'] ? 'true' : 'false', $row['o_updated_at'], $row['o_updated_by_s_id']]);
            }
        }
    }

    /**
     * The first column of the first row (false when there is none).
     *
     * @param list<mixed> $params
     */
    protected static function value(string $sql, array $params = []): mixed
    {
        $statement = self::$db->prepare($sql);
        self::assertNotFalse($statement, $sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    protected static function rows(string $sql, array $params = []): array
    {
        $statement = self::$db->prepare($sql);
        self::assertNotFalse($statement, $sql);
        $statement->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Assert that $pattern matches and return the match and its groups.
     *
     * @return list<string>
     */
    protected static function match(string $pattern, string $subject, string $message = ''): array
    {
        self::assertSame(1, preg_match($pattern, $subject, $match), $message !== '' ? $message : 'no match for ' . $pattern);
        /** @var list<string> $match */
        return $match;
    }

    /**
     * A CSRF token from $html: any form's (manual forms' `csrf` field or a
     * Symfony form's `name[csrf]`), as all use the token id ctnlist.
     */
    protected static function csrfToken(string $html, string $page = 'the page'): string
    {
        return html_entity_decode(self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $html, "CSRF token on {$page}")[1]);
    }

    /** No PHP error output in the page. */
    protected static function assertCleanPage(string $route, string $body): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<b>(Fatal error|Warning|Notice|Deprecated)<\/b>|Uncaught |Stack trace:/',
            $body,
            "PHP error output on {$route}"
        );
    }
}
