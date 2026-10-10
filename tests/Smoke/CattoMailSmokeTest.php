<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * The catto-mail integration on the running development stack, which has no
 * catto-mail configured (see README "catto-mail development route"): the
 * webhook endpoint refuses events it cannot verify, the administration pages
 * say what is missing, and no page reveals a secret.
 */
final class CattoMailSmokeTest extends SmokeTestCase
{
    public function testWebhookEndpointWithoutSecretOrSignature(): void
    {
        $response = self::request(self::client(), 'POST', '/cattomail/webhook', []);
        self::assertContains($response['status'], [401, 503], 'never accepted without a verifiable signature');
        self::assertSame([], array_filter($response['cookies'], static fn(string $c): bool => str_starts_with($c, 'ctnlist_php_session=')), 'no session started');
        self::assertSame(0, (int) self::value("SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_received_at > NOW() - INTERVAL '1 minute'"));
    }

    public function testAdministrationPagesExplainTheConfiguration(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $delivery = self::request($client, 'GET', '/delivery');
        self::assertSame(200, $delivery['status']);
        self::assertStringContainsString('/cattomail/webhook', $delivery['body'], 'the endpoint to register in catto-mail');
        self::assertDoesNotMatchRegularExpression('/shk_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $delivery['body'], 'no key or secret in the page');
        $validation = self::request($client, 'GET', '/address-validation');
        self::assertSame(200, $validation['status']);
        self::assertStringContainsString('catto-mail is not configured', $validation['body']);
        self::assertSame(403, self::request(self::client(), 'GET', '/delivery')['status'], 'administration only');
    }

    public function testUnsignedOneClickLinksAreRefused(): void
    {
        $uuid = self::$admin['s_uuid'];
        self::assertSame(404, self::request(self::client(), 'GET', "/unsubscribe-link/{$uuid}/ALL/-/forged")['status']);
        self::assertSame(404, self::request(self::client(), 'POST', "/unsubscribe-link/{$uuid}/ALL/-/forged", ['List-Unsubscribe' => 'One-Click'])['status']);
    }

    /**
     * Recovery actions on /delivery are shown and accepted only with their
     * permission: logs.view sees the state, queue.process may retry and
     * reprocess. (The smoke database is disposable; these rows go with it.)
     */
    public function testRecoveryActionsFollowPermissions(): void
    {
        $run = (int) self::value("INSERT INTO cattomail_runs (cr_kind, cr_finished_at, cr_created_at) VALUES ('campaign', now(), '2000-01-01') RETURNING cr_id");
        $job = (int) self::value("INSERT INTO cattomail_send_jobs (csj_cr_id, csj_seq, csj_idempotency_key, csj_message_class, csj_request, csj_status, csj_created_at)
            VALUES (?, 1, ?, 'transactional', '{}', 'ready', '2000-01-01') RETURNING csj_id", [$run, bin2hex(random_bytes(16))]);
        $batch = (int) self::value('INSERT INTO cattomail_batches (cb_csj_id, cb_seq, cb_idempotency_key) VALUES (?, 1, ?) RETURNING cb_id', [$job, bin2hex(random_bytes(16))]);
        self::$db->prepare("INSERT INTO cattomail_recipients (crp_csj_id, crp_cb_id, crp_email, crp_type, crp_subject, crp_html) VALUES (?, ?, 'smoke@example.com', 'PROOF', 'S', '<p>x</p>')")->execute([$job, $batch]);
        self::$db->prepare('UPDATE cattomail_send_jobs SET csj_total = 1 WHERE csj_id = ?')->execute([$job]);
        $event = sprintf('0199d000-0000-7000-8000-%012x', random_int(1, 0xFFFFFFFFFFFF));
        self::$db->prepare("INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_received_at) VALUES (?, 'webhook.test', '{}', '2000-01-01')")->execute([$event]);

        $viewer = self::withRole('smoke-viewer@ctnlist.test', ['logs.view']);
        $status = self::request($viewer, 'GET', '/delivery')['body'];
        self::assertStringContainsString('Stuck work', $status);
        self::assertMatchesRegularExpression('/send jobs not sealed<\/span><span class="fw-semibold">1/i', $status);
        $jobPage = self::request($viewer, 'GET', '/delivery/job/' . $job)['body'];
        self::assertStringContainsString('>Stuck</span>', $jobPage);
        self::assertStringNotContainsString('Retry sending', $jobPage, 'no retry without queue.process');
        self::assertStringNotContainsString('Process again', self::request($viewer, 'GET', '/delivery/webhooks')['body']);
        $csrf = self::csrfToken($status);
        self::assertSame(403, self::request($viewer, 'POST', '/delivery/job/' . $job . '/retry', ['csrf' => $csrf])['status'], 'refused on the server too');
        self::assertSame(403, self::request($viewer, 'POST', '/delivery/webhooks/' . $event . '/reprocess', ['csrf' => $csrf])['status']);

        $operator = self::withRole('smoke-operator@ctnlist.test', ['logs.view', 'queue.process']);
        $jobPage = self::request($operator, 'GET', '/delivery/job/' . $job)['body'];
        self::assertStringContainsString('Retry sending', $jobPage);
        $retried = self::request($operator, 'POST', '/delivery/job/' . $job . '/retry', ['csrf' => self::csrfToken($jobPage)]);
        self::assertSame(302, $retried['status']);
        self::assertStringContainsString('catto-mail is not configured', self::request($operator, 'GET', '/delivery/job/' . $job)['body'], 'an honest answer, nothing fabricated');
        self::assertSame('ready', self::value('SELECT csj_status FROM cattomail_send_jobs WHERE csj_id = ?', [$job]), 'the job waits; no state is set by hand');

        $webhooks = self::request($operator, 'GET', '/delivery/webhooks')['body'];
        self::assertStringContainsString('Process again', $webhooks);
        self::assertSame(302, self::request($operator, 'POST', '/delivery/webhooks/' . $event . '/reprocess', ['csrf' => self::csrfToken($webhooks)])['status']);
        self::assertSame('test event', self::value('SELECT cwe_outcome FROM cattomail_webhook_events WHERE cwe_event_id = ?', [$event]));
    }

    /** Large histories paginate, with their filters kept in the page links (synthetic rows in the disposable smoke database). */
    public function testHistoryPagesPaginate(): void
    {
        $job = sprintf('0199e000-0000-7000-8000-%012x', random_int(1, 0xFFFFFFFFFFFF));
        self::$db->prepare(
            "INSERT INTO cattomail_webhook_events (cwe_event_id, cwe_type, cwe_payload, cwe_related_id, cwe_received_at, cwe_processed_at, cwe_outcome)
             SELECT ('0199e111-0000-7000-8000-' || lpad(to_hex(n), 12, '0'))::uuid, 'something.new', NULL, ?, now(), now(), 'ignored unknown event type'
             FROM generate_series(1, 45) n"
        )->execute([$job]);
        foreach ([1, 2] as $copy) {
            $run = (int) self::value("INSERT INTO cattomail_runs (cr_kind, cr_finished_at) VALUES ('proof', now()) RETURNING cr_id");
            self::$db->prepare("INSERT INTO cattomail_send_jobs (csj_cr_id, csj_seq, csj_idempotency_key, csj_message_class, csj_request, csj_status)
                SELECT ?, n, md5(random()::text || n), 'transactional', '{}', 'completed' FROM generate_series(1, 3) n")->execute([$run]);
        }
        $admin = self::client();
        self::loginAsAdmin($admin);

        $page = self::request($admin, 'GET', '/delivery/webhooks/2?r=20&f=ignored&j=' . $job)['body'];
        self::assertSame(20, substr_count($page, '<code>something.new</code>'), 'one page of 20, not all 45');
        self::assertStringContainsString('aria-current="page">2</span>', $page);
        self::assertMatchesRegularExpression('#href="/delivery/webhooks/3\?[^"]*f=ignored[^"]*"#', $page, 'filters kept in the links');
        self::assertMatchesRegularExpression('#href="/delivery/webhooks/3\?[^"]*j=' . $job . '#', $page);
        self::assertStringContainsString('body pruned', $page);
        self::assertStringContainsString('<span class="badge theme-secondary">ignored</span>', $page);

        $runs = self::request($admin, 'GET', '/delivery/runs?r=1')['body'];
        self::assertStringContainsString('<nav aria-label="Pagination">', $runs);
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
