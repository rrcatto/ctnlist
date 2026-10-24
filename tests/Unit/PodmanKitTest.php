<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The rootless Podman kit (deploy/podman). What makes a site safe to run this way is written in a few lines of its
 * files, so each is held here, and the kit is held to the server templates it stands in for (deploy/php-fpm,
 * deploy/nginx, deploy/systemd). `bin/dev test-podman-kit` runs the kit itself in a stand-in server.
 */
final class PodmanKitTest extends TestCase
{
    public function testTheImagesArePinnedToExactReleases(): void
    {
        $pinned = preg_match('#^ARG PHP_IMAGE=docker\.io/library/php:(\d+\.\d+\.\d+)-fpm-bookworm$#m', self::kit('Containerfile'), $m) === 1 ? $m[1] : self::fail('the PHP image names no exact release');
        /** @var array{config: array{platform: array{php: string}}} $composer */
        $composer = json_decode(self::file('composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue(version_compare($pinned, $composer['config']['platform']['php'], '>='), 'PHP ' . $pinned . ' is no older than the minimum');
        $script = self::kit('ctnlist-podman.sh');
        self::assertMatchesRegularExpression('#^WEB_IMAGE="docker\.io/nginxinc/nginx-unprivileged:\d+\.\d+\.\d+-alpine"$#m', $script);
        $mailpit = preg_match('#^MAILPIT_IMAGE="(docker\.io/axllent/mailpit:v\d+\.\d+\.\d+)"$#m', $script, $m) === 1 ? $m[1] : self::fail('the Mailpit image names no exact release');
        self::assertStringContainsString('image: ' . $mailpit . "\n", self::file('compose.yaml'), 'the Mailpit the development stack runs');
    }

    /** The image's PHP-FPM is the server's pool template, rewritten only to listen inside the pod and to name no user. */
    public function testPhpFpmRunsCtnlistsOwnPoolInsideThePodOnly(): void
    {
        $image = self::kit('Containerfile');
        self::assertStringContainsString('COPY php-fpm/ctnlist.conf.example /usr/local/etc/php-fpm.d/ctnlist.conf', $image);
        self::assertStringContainsString('rm -f docker.conf www.conf www.conf.default zz-docker.conf', $image, 'the image\'s own pool logs query strings and passes the environment on');
        self::assertStringContainsString("-e 's#^listen = .*#listen = 127.0.0.1:9000#'", $image);
        self::assertStringContainsString("-e '/^user = /d' -e '/^group = /d'", $image);
        $pool = self::file('deploy/php-fpm/ctnlist.conf.example');
        foreach (['/^\[ctnlist-<installation>\]$/m', '/^user = /m', '/^group = /m', '/^listen = /m', '/^clear_env = yes$/m', '/^catch_workers_output = yes$/m'] as $line) {
            self::assertMatchesRegularExpression($line, $pool, 'the pool template has the line the image relies on');
        }
        self::assertDoesNotMatchRegularExpression('/^env\[/m', $pool);
    }

    public function testPhpWritesOnlyVarAndLogsAndThePodIsOnlyOnTheServersOwnAddress(): void
    {
        $pod = self::kit('ctnlist.yaml.in');
        foreach (['code' => '/usr/local/lib/php/ctnlist/@VERSION@', 'env' => '/var/www/@INSTALLATION@/.env', 'banlist' => '/etc/ctnlist/ban.env'] as $volume => $path) {
            self::assertMatchesRegularExpression('#- name: ' . $volume . '\n\s+mountPath: ' . preg_quote($path, '#') . '\n\s+readOnly: true\n#', $pod, $volume . ' is read-only');
        }
        self::assertSame(2, preg_match_all('#- name: public\n\s+mountPath: /var/www/@INSTALLATION@/public_html\n\s+readOnly: true\n#', $pod), 'public_html is read-only to PHP and to the web server');
        // ban.env only for an installation with the suppression database; without one, catto-mail's suppression.
        self::assertMatchesRegularExpression('@# banlist:begin\n\s+- name: banlist\n\s+mountPath: /etc/ctnlist/ban.env\n\s+readOnly: true\n\s+# banlist:end\n@', $pod);
        self::assertMatchesRegularExpression('@# banlist:begin\n\s+- name: banlist\n\s+hostPath:\n\s+path: \@KIT\@/config/ban.env\n\s+type: File\n\s+# banlist:end\n@', $pod);
        $script = self::kit('ctnlist-podman.sh');
        self::assertStringContainsString("else mp+=(-e '/# banlist:begin/,/# banlist:end/d'); fi", $script);
        self::assertStringContainsString('suppression=(SUPPRESSION_PROVIDER cattomail); [[ "$BANLIST" == yes ]] && suppression=(SUPPRESSION_PROVIDER banlist', $script);
        preg_match_all('#- name: (\S+)\n\s+mountPath: \S+\n(?!\s+readOnly: true\n)#', $pod, $writable);
        self::assertSame(['var', 'logs', 'mailpit-data'], $writable[1], 'nothing else is writable');
        preg_match_all('#- name: ([A-Z_]+)\n\s+value: #', $pod, $environment);
        self::assertSame(['CTNLIST_INSTANCE_DIR'], $environment[1], 'the worker sees the configuration the web server sees');
        $unit = self::kit('ctnlist.kube.in');
        self::assertStringContainsString('PublishPort=127.0.0.1:@PORT@:8080', $unit);
        self::assertStringContainsString('UserNS=keep-id', $unit);
        self::assertStringContainsString('Network=slirp4netns:allow_host_loopback=true', $unit, 'the server\'s PostgreSQL and mail server at 10.0.2.2; 10.0.2.100 is the hop web.conf.in believes');
    }

    /**
     * The pod's web server is the edge for PHP: it believes the forwarded address only from Podman's own hop, and only
     * the last one (2026-10-24: a hop of 0.0.0.0/0 with real_ip_recursive takes an address the visitor wrote).
     */
    public function testThePodsWebServerHandsPhpTheVisitorsRequest(): void
    {
        $web = self::kit('web.conf.in');
        preg_match_all('/^\s*set_real_ip_from\s+(\S+);/m', $web, $trusted);
        self::assertSame(['10.0.2.100'], $trusted[1]);
        self::assertStringContainsString('real_ip_header X-Forwarded-For;', $web);
        self::assertStringContainsString("if (\$http_x_forwarded_proto = http) {\n        return 301 https://\$host\$request_uri;", $web);
        self::assertLogsNoQueryString($web);
        $fastcgi = self::kit('fastcgi.conf');
        foreach (['fastcgi_pass 127.0.0.1:9000;', 'fastcgi_param HTTPS $ctnlist_https if_not_empty;', 'fastcgi_param SERVER_PORT $ctnlist_port;', 'fastcgi_read_timeout 300s;'] as $line) {
            self::assertStringContainsString($line, $fastcgi);
        }
        // The rest is the server's FastCGI snippet: the script, and no forwarding header reaching PHP.
        foreach (self::lines(self::file('deploy/nginx/ctnlist-fastcgi.conf.example'), '/^fastcgi_param (SCRIPT_|DOCUMENT_ROOT|HTTP_X_FORWARDED_)/') as $line) {
            self::assertStringContainsString($line, $fastcgi);
        }
    }

    /** The pod serves what the server template serves, with the same limits and the same single entry point. */
    public function testThePodsWebServerKeepsTheServerTemplatesRules(): void
    {
        $web = self::kit('web.conf.in');
        $server = self::file('deploy/nginx/ctnlist.conf.example');
        foreach (['client_max_body_size 32m;', 'client_max_body_size 1m;', 'location ^~ /assets/ {', 'location ^~ /vendor/ {', 'location = /cattomail/webhook {',
            'location = /health {', "location = /index.php {\n        internal;", "location ~ \\.php\$ {\n        return 404;", "location ~ /\\. {\n        deny all;"] as $rule) {
            self::assertStringContainsString($rule, $server, 'the server template has it');
            self::assertStringContainsString($rule, $web);
        }
    }

    public function testTheServersWebServerPassesTheVisitorsAddressAndWaitsForLongRequests(): void
    {
        $host = self::kit('host-nginx.conf.example');
        foreach (['location ^~ / {', 'proxy_pass http://127.0.0.1:<port>;', 'proxy_set_header Host $host;', 'proxy_set_header X-Forwarded-For $remote_addr;',
            'proxy_set_header X-Forwarded-Proto $scheme;', 'proxy_read_timeout 300s;', 'client_max_body_size 32m;'] as $line) {
            self::assertStringContainsString($line, $host);
        }
        self::assertLogsNoQueryString($host);
    }

    public function testTheTimersRunTheServerUnitsCommandsOnTheirSchedules(): void
    {
        foreach (['worker', 'maintenance'] as $unit) {
            $service = self::kit('ctnlist-' . $unit . '.service.in');
            self::assertSame(self::match('#/bin/console (.+)$#m', self::file('deploy/systemd/ctnlist-' . $unit . '@.service')), self::match('#/bin/console (.+)$#m', $service), $unit);
            self::assertStringContainsString('ExecStart=@PODMAN@ exec @POD@-php php /usr/local/lib/php/ctnlist/@VERSION@/bin/console ', $service, 'in the pod, with its environment');
            self::assertStringContainsString("Requisite=@POD@.service\n", $service);
            self::assertSame(self::match('/^OnCalendar=(.+)$/m', self::file('deploy/systemd/ctnlist-' . $unit . '@.timer')), self::match('/^OnCalendar=(.+)$/m', self::kit('ctnlist-' . $unit . '.timer.in')), $unit);
        }
    }

    public function testTheScriptsAreValidAndKnowWhoRunsThem(): void
    {
        foreach (['ctnlist-podman.sh', 'root-prepare.sh'] as $script) {
            $path = self::root() . '/deploy/podman/' . $script;
            self::assertTrue(is_executable($path), $script . ' is executable');
            exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
            self::assertSame(0, $status, $script . ': ' . implode("\n", $output));
        }
        $kit = self::kit('ctnlist-podman.sh');
        self::assertStringContainsString('[[ $EUID -ne 0 ]] || fail', $kit, 'the site runs as its own user, never root');
        self::assertStringContainsString('[ "$(id -u)" -eq 0 ] || fail', self::kit('root-prepare.sh'));
        self::assertStringContainsString('chmod 600 "$SITE/.env.new"', $kit, 'the settings are the site user\'s alone');
        self::assertStringContainsString('$in = explode("\0", (string) stream_get_contents(STDIN));', $kit, 'settings reach PHP on standard input, never on a command line');
        self::assertStringContainsString("\\set pw \$password\nCREATE ROLE", self::kit('root-prepare.sh'), 'and passwords reach psql the same way');
        self::assertStringContainsString('found" == "ok $ours" ]] || fail', $kit, 'a new installation never takes a database holding other tables');
    }

    public function testTheKitNamesNoParticularServer(): void
    {
        foreach (glob(self::root() . '/deploy/podman/*') ?: [] as $path) {
            $text = (string) file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('#\b(?:\d{1,3}\.){3}\d{1,3}\b#', str_replace(['127.0.0.1', '10.0.2.2', '10.0.2.100', '0.0.0.0'], '', $text), basename($path) . ' names no server address');
            self::assertStringNotContainsString('/media/', $text, basename($path) . ' names no workstation path');
            self::assertStringNotContainsString('/home/', $text, basename($path) . ' names no home directory (the scripts use $HOME)');
        }
    }

    private static function assertLogsNoQueryString(string $config): void
    {
        $formats = self::lines($config, '/^\s*log_format /');
        self::assertNotEmpty($formats);
        foreach ($formats as $format) {
            self::assertStringContainsString('$uri', $format);
            self::assertDoesNotMatchRegularExpression('/\$(request_uri|request|args|query_string|is_args)\b/', $format, 'a sign-in link\'s token is never logged');
        }
    }

    /** @return list<string> the trimmed lines of $text that match $pattern */
    private static function lines(string $text, string $pattern): array
    {
        return array_values(array_map('trim', preg_grep($pattern, explode("\n", $text)) ?: []));
    }

    private static function match(string $pattern, string $text): string
    {
        return preg_match($pattern, $text, $m) === 1 ? $m[1] : self::fail('no match for ' . $pattern);
    }

    private static function kit(string $file): string
    {
        return self::file('deploy/podman/' . $file);
    }

    private static function file(string $path): string
    {
        return (string) file_get_contents(self::root() . '/' . $path);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
