<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Connect and Disconnect on the Delivery page, over HTTP on the running
 * stack, which has no mail service: who is offered them, what the browser is
 * sent to the mail service with, what comes back, and that nothing is stored
 * unless the mail service answers. (The exchange itself, against a fake mail
 * service, is in Integration\CattoMail\ConnectFlowTest.)
 */
final class MailServiceConnectionTest extends SmokeTestCase
{
    /** A mail service that cannot exist: .invalid never resolves. */
    private const SERVICE = 'https://mail-service.invalid';

    protected function tearDown(): void
    {
        self::$db->prepare("DELETE FROM options WHERE o_key LIKE 'cattomail:connect%' OR o_key LIKE 'cattomail:connection%' OR o_key LIKE 'setting:CATTOMAIL_%' OR o_key = 'cattomail:sending-domains'")->execute();
        parent::tearDown();
    }

    public function testOnlyAdministratorsAreOfferedConnect(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $page = self::request($admin, 'GET', '/delivery')['body'];
        self::assertStringContainsString('<span class="badge theme-warning">Not connected</span>', $page);
        self::assertStringContainsString('<form name="mail_service" method="post" action="/delivery/connect"', $page);
        self::assertStringNotContainsString('action="/delivery/disconnect"', $page, 'nothing to disconnect');

        // settings.manage alone is not enough: whoever chooses the mail service receives every campaign.
        $manager = self::withRole('smoke-mailservice@ctnlist.test', ['logs.view', 'settings.manage']);
        $page = self::request($manager, 'GET', '/delivery')['body'];
        self::assertStringContainsString('An administrator can connect the mail service here.', $page);
        self::assertStringNotContainsString('name="mail_service"', $page);
        $csrf = self::csrfToken($page);
        self::assertSame(403, self::request($manager, 'POST', '/delivery/connect', ['mail_service[service]' => self::SERVICE, 'mail_service[csrf]' => $csrf])['status']);
        self::assertSame(403, self::request($manager, 'POST', '/delivery/disconnect', ['csrf' => $csrf])['status']);
        self::assertSame(403, self::request($manager, 'GET', '/delivery/connect/callback?state=x&code=y')['status']);
        self::assertSame(403, self::request($manager, 'POST', '/delivery/connect/complete', ['csrf' => $csrf, 'state' => 'x', 'code' => 'y'])['status']);
        self::assertSame(403, self::request(self::client(), 'GET', '/delivery/connect/callback?state=x&code=y')['status'], 'not for visitors either');
        self::assertFalse(self::value("SELECT 1 FROM options WHERE o_key = 'cattomail:connect:pending'"), 'nothing was started');
    }

    public function testConnectSendsTheBrowserToTheMailServiceAndStoresNothingUntilItAnswers(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);

        // An address that cannot be a mail service is refused at its field, and what was typed is kept.
        $refused = self::submitForm($admin, '/delivery', 'mail_service', ['mail_service[service]' => 'http://mail.example.org']);
        self::assertSame(422, $refused['status']);
        self::assertStringContainsString('id="mail_service_service_error1"', $refused['body']);
        self::assertStringContainsString('must start with https://', $refused['body']);
        self::assertStringContainsString('value="http://mail.example.org"', $refused['body']);
        self::assertSame(422, self::submitForm($admin, '/delivery', 'mail_service', ['mail_service[service]' => ''])['status']);
        self::assertFalse(self::value("SELECT 1 FROM options WHERE o_key = 'cattomail:connect:pending'"));

        // A mail service address (any of its pages): the answer is a page that forwards the browser to its connect page.
        $started = self::submitForm($admin, '/delivery', 'mail_service', ['mail_service[service]' => self::SERVICE . '/dashboard/login']);
        self::assertSame(200, $started['status'], 'a page, not a redirect: the Content-Security-Policy keeps forms on this site');
        $address = html_entity_decode(self::match('#<meta http-equiv="refresh" content="0;url=([^"]+)">#', $started['body'], 'the forwarding address')[1]);
        self::assertStringStartsWith(self::SERVICE . '/dashboard/connect?', $address);
        self::assertStringContainsString('<a class="btn-solid theme-primary" href="' . htmlspecialchars($address) . '" rel="noreferrer">', $started['body'], 'and a link, should the browser not forward');
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);
        $sent = array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $query);
        self::assertSame(['application_name', 'redirect_uri', 'webhook_url', 'state', 'code_challenge', 'code_challenge_method', 'sending_domain'], array_keys($sent));
        self::assertMatchesRegularExpression('/^([a-z0-9-]+\.)+[a-z0-9-]+$/', $sent['sending_domain'], 'the domain of the site\'s From address');
        // The address typed while none was known is kept (its origin), so the next Connect needs no typing.
        self::assertSame(self::SERVICE, self::value("SELECT o_value FROM options WHERE o_key = 'setting:CATTOMAIL_API_BASE_URL'"));
        self::assertStringNotContainsString('name="mail_service[service]"', self::request($admin, 'GET', '/delivery')['body']);
        self::assertStringEndsWith('/delivery/connect/callback', $sent['redirect_uri']);
        self::assertStringEndsWith('/cattomail/webhook', $sent['webhook_url']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sent['state']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sent['code_challenge']);
        self::assertSame('S256', $sent['code_challenge_method']);
        $state = $sent['state'];
        $pending = (string) self::value("SELECT o_value FROM options WHERE o_key = 'cattomail:connect:pending'");
        self::assertStringContainsString('"verifier":"enc:v1:', $pending, 'the code verifier waits here encrypted, and was never sent');

        // An answer that is not this request's changes nothing and offers nothing.
        $foreign = self::request($admin, 'GET', '/delivery/connect/callback?code=abc&state=' . strrev($state));
        self::assertSame(200, $foreign['status']);
        self::assertStringContainsString('does not belong to a connection request that waits here', $foreign['body']);
        self::assertStringNotContainsString('action="/delivery/connect/complete"', $foreign['body']);

        // Declined at the mail service: said so, in the service's own words as plain text.
        $declined = self::request($admin, 'GET', '/delivery/connect/callback?error=access_denied&error_description=' . rawurlencode('Declined <b>there</b>') . '&state=' . $state);
        self::assertStringContainsString('was not approved at mail-service.invalid: Declined &lt;b&gt;there&lt;/b&gt;', $declined['body']);
        self::assertStringNotContainsString('action="/delivery/connect/complete"', $declined['body']);

        // Approved: the GET only shows the Finish form, which the site script submits at once.
        $approved = self::request($admin, 'GET', '/delivery/connect/callback?code=the-code&state=' . $state);
        self::assertSame(200, $approved['status']);
        self::assertStringContainsString('<form class="card app-narrow ms-0" method="post" action="/delivery/connect/complete" data-autosubmit>', $approved['body']);
        self::assertStringContainsString('<input type="hidden" name="code" value="the-code">', $approved['body']);
        self::assertFalse(self::value("SELECT 1 FROM options WHERE o_key LIKE 'setting:CATTOMAIL_%' AND o_key <> 'setting:CATTOMAIL_API_BASE_URL'"), 'opening the answer stores nothing');

        // Finishing asks the mail service, which here does not exist: said so, Finish stays possible, nothing is stored.
        self::assertSame(403, self::request($admin, 'POST', '/delivery/connect/complete', ['state' => $state, 'code' => 'the-code'])['status'], 'not without the CSRF token');
        $finished = self::request($admin, 'POST', '/delivery/connect/complete', ['csrf' => self::csrfToken($approved['body']), 'state' => $state, 'code' => 'the-code']);
        self::assertSame(422, $finished['status']);
        self::assertStringContainsString('could not be reached to finish the connection', $finished['body']);
        self::assertStringContainsString('action="/delivery/connect/complete">', $finished['body'], 'the form again, not submitted by itself this time');
        self::assertFalse(self::value("SELECT 1 FROM options WHERE o_key LIKE 'setting:CATTOMAIL_%' AND o_key <> 'setting:CATTOMAIL_API_BASE_URL'"));
        self::assertStringContainsString('Not connected', self::request($admin, 'GET', '/delivery')['body']);
    }

    public function testAStoredConnectionShowsNoSecretAndCanBeForgotten(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        $none = self::request($admin, 'POST', '/delivery/disconnect', ['csrf' => self::csrfToken(self::request($admin, 'GET', '/delivery')['body'])]);
        self::assertSame(302, $none['status']);
        self::assertStringContainsString('No connection is stored here.', self::request($admin, 'GET', '/delivery')['body']);

        // A connection whose key was encrypted with another APP_SETTINGS_KEY (the key was replaced, or the database restored elsewhere).
        $insert = self::$db->prepare('INSERT INTO options (o_key, o_value, o_secret) VALUES (?, ?, ?)');
        $insert->execute(['setting:CATTOMAIL_API_BASE_URL', self::SERVICE . '/v1', 'false']);
        $insert->execute(['setting:CATTOMAIL_API_KEY', 'enc:v1:00000000:' . base64_encode(random_bytes(48)), 'true']);
        $insert->execute(['setting:CATTOMAIL_WEBHOOK_SECRET', 'enc:v1:00000000:' . base64_encode(random_bytes(48)), 'true']);
        $insert->execute(['setting:CATTOMAIL_CONNECTION', (string) json_encode(['service' => self::SERVICE, 'client_name' => 'Example Ltd', 'application_name' => 'Smoke']), 'false']);

        $page = self::request($admin, 'GET', '/delivery')['body'];
        self::assertStringContainsString('A connection to ' . self::SERVICE . ' is stored, but its key cannot be read', $page);
        self::assertStringContainsString('different APP_SETTINGS_KEY', $page);
        self::assertDoesNotMatchRegularExpression('/enc:v1:|shk_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $page, 'neither a secret nor its stored form is shown');
        self::assertStringContainsString('action="/delivery/disconnect"', $page);
        // The Settings page shows the address but does not change it while a connection is stored, and never shows the rest.
        $settings = self::request($admin, 'GET', '/settings')['body'];
        self::assertStringContainsString('This site is connected to the mail service at <strong>' . self::SERVICE . '/v1</strong>', $settings);
        self::assertMatchesRegularExpression('#<input type="url"[^>]*id="settings_mail_service_CATTOMAIL_API_BASE_URL"[^>]*readonly#', $settings);
        self::assertDoesNotMatchRegularExpression('/CATTOMAIL_(API_KEY|WEBHOOK_SECRET|CONNECTION)/', $settings);
        $changed = self::submitForm($admin, '/settings', 'settings_mail_service', ['settings_mail_service[CATTOMAIL_API_BASE_URL]' => 'https://other.example']);
        self::assertSame(422, $changed['status']);
        self::assertStringContainsString('disconnect on the Delivery page before changing the address', $changed['body']);
        self::assertSame(self::SERVICE . '/v1', self::value("SELECT o_value FROM options WHERE o_key = 'setting:CATTOMAIL_API_BASE_URL'"));

        // It cannot be ended at the mail service with a key that cannot be read; it can be forgotten.
        self::request($admin, 'POST', '/delivery/disconnect', ['csrf' => self::csrfToken($page)]);
        $page = self::request($admin, 'GET', '/delivery')['body'];
        self::assertStringContainsString('so the connection cannot be ended from here', $page);
        self::assertSame(4, (int) self::value("SELECT COUNT(*) FROM options WHERE o_key LIKE 'setting:CATTOMAIL_%'"), 'kept');
        self::assertSame(302, self::request($admin, 'POST', '/delivery/disconnect', ['csrf' => self::csrfToken($page), 'forget' => '1'])['status']);
        $page = self::request($admin, 'GET', '/delivery')['body'];
        self::assertStringContainsString('The stored connection was forgotten.', $page);
        self::assertStringContainsString('<span class="badge theme-warning">Not connected</span>', $page);
        self::assertSame([['o_key' => 'setting:CATTOMAIL_API_BASE_URL']], self::rows("SELECT o_key FROM options WHERE o_key LIKE 'setting:CATTOMAIL_%'"), 'the address is kept for the next connection');
        // Now the Settings page can change it (administrators only).
        self::assertSame(302, self::submitForm($admin, '/settings', 'settings_mail_service', ['settings_mail_service[CATTOMAIL_API_BASE_URL]' => 'https://other.example'])['status']);
        self::assertSame('https://other.example', self::value("SELECT o_value FROM options WHERE o_key = 'setting:CATTOMAIL_API_BASE_URL'"));
        self::assertSame(422, self::submitForm($admin, '/settings', 'settings_mail_service', ['settings_mail_service[CATTOMAIL_API_BASE_URL]' => 'http://other.example'])['status'], 'https only');
    }

    /** Settings › Set up sending (owner decision 2026-10-27): the steps, who may take them, the address given once, the notice. */
    public function testSetUpSendingGuidesStepByStep(): void
    {
        $admin = self::client();
        self::loginAsAdmin($admin);
        // Until sending is set up, administration pages say so and link here.
        self::assertStringContainsString('Sending is not set up yet: 0 of 4 steps done.', self::request($admin, 'GET', '/messages')['body']);
        $page = self::request($admin, 'GET', '/delivery/setup')['body'];
        // An answer about the From domain left by an earlier connection does not count without a connection.
        $domain = html_entity_decode(self::match('#the domain of this site\'s From address \(([^)]+)\)#', $page, 'the From domain')[1]);
        self::$db->prepare("INSERT INTO options (o_key, o_value) VALUES ('cattomail:sending-domains', ?)")->execute([(string) json_encode(['checked_at' => '2026-10-27 09:00:00',
            'domains' => [['domain' => $domain, 'status' => 'verified', 'dkim_status' => 'active', 'can_send' => true, 'setup_url' => '']]])]);
        $page = self::request($admin, 'GET', '/delivery/setup')['body'];
        self::assertStringContainsString('<strong>0 of 4</strong> steps done.', $page);
        self::assertDoesNotMatchRegularExpression('#id="step-domain".*?<span class="badge theme-success ms-1">Done</span>.*?id="step-test"#s', $page);
        self::assertStringNotContainsString('Sending is not set up yet', $page, 'not on the page itself');
        self::assertStringContainsString('aria-current="step"', $page);
        self::assertMatchesRegularExpression('#id="step-account" aria-current="step"#', $page, 'the first step is open');
        self::assertStringContainsString('ask the people who run it for an invitation', $page);
        self::assertNoNestedForms('/delivery/setup', $page);

        // Step 1: the address, given once and kept.
        $refused = self::request($admin, 'POST', '/delivery/setup/address', self::formFields($page, 'mail_service', ['mail_service[service]' => 'http://mail.example.org']));
        self::assertSame(302, $refused['status']);
        self::assertFalse(self::value("SELECT 1 FROM options WHERE o_key = 'setting:CATTOMAIL_API_BASE_URL'"), 'not https: not kept');
        $kept = self::region($admin, '/delivery/setup/address', 'sending-setup', 'POST', self::formFields($page, 'mail_service', ['mail_service[service]' => self::SERVICE . '/dashboard']), '/delivery/setup');
        self::assertRegion('sending-setup', $kept);
        self::assertStringContainsString('address is kept: ' . self::SERVICE, $kept['body']);
        self::assertStringContainsString('<strong>1 of 4</strong> steps done.', $kept['body']);
        self::assertMatchesRegularExpression('#id="step-connect" aria-current="step"#', $kept['body']);
        // Step 2: Connect without typing, coming back here.
        self::assertStringNotContainsString('name="mail_service[service]"', $kept['body']);
        self::assertStringContainsString('<input type="hidden" name="return" value="setup">', $kept['body']);
        self::assertStringContainsString('data-copy="setup-address"', $kept['body'], 'the address has a copy button');

        // Someone with settings.manage but not an administrator follows the steps without the buttons.
        $manager = self::withRole('smoke-setup-manager@ctnlist.test', ['settings.manage']);
        $followed = self::request($manager, 'GET', '/delivery/setup')['body'];
        self::assertStringContainsString('An administrator takes these steps', $followed);
        self::assertStringNotContainsString('name="mail_service"', $followed);
        self::assertSame(403, self::request($manager, 'POST', '/delivery/setup/test', ['csrf' => self::csrfToken($followed)])['status']);
        self::assertStringNotContainsString('id="pane-mail_service"', self::request($manager, 'GET', '/settings')['body'], 'the mail service\'s address is an administrator\'s setting');
    }

    /** @param list<string> $permissions */
    private static function withRole(string $email, array $permissions): \CurlHandle
    {
        $key = 'smoke-' . bin2hex(random_bytes(3));
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([$email]);
        $id = (int) self::value('SELECT s_id FROM subscribers WHERE s_email = ?', [$email]);
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([$key, 'Smoke ' . $key]);
        $grant = self::$db->prepare('INSERT INTO role_permissions (rp_r_id, rp_ap_id) SELECT r.r_id, ap.ap_id FROM roles r JOIN acl_permissions ap ON ap.ap_key = ? WHERE r.r_key = ?');
        foreach ($permissions as $permission) {
            $grant->execute([$permission, $key]);
        }
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) SELECT ?, r_id FROM roles WHERE r_key = ?')->execute([$id, $key]);
        $client = self::client();
        self::assertSame(302, self::signInWithLink($client, self::issueLoginToken($id))['status']);
        return $client;
    }
}
