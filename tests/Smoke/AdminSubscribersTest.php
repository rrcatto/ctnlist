<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Subscriber administration. */
final class AdminSubscribersTest extends SmokeTestCase
{
    private ?string $originalName = null;

    protected function tearDown(): void
    {
        if ($this->originalName !== null) {
            self::$db->prepare('UPDATE subscribers SET s_fname = ? WHERE s_id = ?')->execute([$this->originalName, self::$admin['s_id']]);
        }
        parent::tearDown();
    }

    public function testListSearchAndEdit(): void
    {
        $this->originalName = (string) self::value('SELECT s_fname FROM subscribers WHERE s_id = ' . self::$admin['s_id']);
        $client = self::client();
        self::loginAsAdmin($client);

        $list = self::request($client, 'GET', '/subscribers?e=admin%40ctnlist&r=5')['body'];
        self::assertStringContainsString(self::$admin['s_email'], $list);
        self::assertMatchesRegularExpression('#ALL <span class="badge text-bg-success">confirmed</span>#', $list, 'membership badge');
        self::assertStringContainsString('There are no matching subscribers.', self::request($client, 'GET', '/subscribers?e=no-such-person')['body']);

        $edit = '/subscribers/' . self::$admin['s_uuid'];
        $saved = self::submitAndFollow($client, $edit, 'subscriber', ['subscriber[firstName]' => 'Dev', 'subscriber[lastName]' => 'Admin', 'subscriber[priority]' => '0']);
        self::assertStringContainsString('Subscriber saved.', $saved);
        $select = self::match('#<select id="subscriber_listId"[^>]*>\s*<option value="">No list</option>(.*?)</select>#s', $saved, 'no list invitation unless one is chosen');
        self::assertStringNotContainsString('selected', $select[1]);

        $invalid = self::submitForm($client, $edit, 'subscriber', ['subscriber[firstName]' => str_repeat('x', 101), 'subscriber[lastName]' => 'Kept', 'subscriber[priority]' => 'high']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="subscriber_firstName_error1">This value is too long#', $invalid['body']);
        self::assertMatchesRegularExpression('#id="subscriber_priority_error1">Enter a whole number#', $invalid['body']);
        self::assertStringContainsString('value="Kept"', $invalid['body'], 'values are kept');
        self::assertSame('Dev', (string) self::value('SELECT s_fname FROM subscribers WHERE s_id = ' . self::$admin['s_id']), 'nothing saved');

        self::assertSame(404, self::request($client, 'GET', '/subscribe/01a10309-8535-7ba9-a26a-000000000000')['status']);
        self::assertSame(403, self::request(self::client(), 'GET', '/subscribers/' . self::$admin['s_uuid'])['status'], 'anonymous');
    }

    public function testBulkFormsValidateBeforeChangingAnything(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $subscribers = (string) self::value('SELECT COUNT(*) FROM subscribers');

        $empty = self::submitForm($client, '/bulk-subscribe', 'bulk_subscribe', ['bulk_subscribe[emails]' => '']);
        self::assertSame(422, $empty['status']);
        self::assertMatchesRegularExpression('#id="bulk_subscribe_emails_error1">Enter at least one email address#', $empty['body']);
        $unusable = self::submitForm($client, '/bulk-subscribe', 'bulk_subscribe', ['bulk_subscribe[emails]' => 'nobody at example', 'bulk_subscribe[priority]' => '7']);
        self::assertMatchesRegularExpression('#id="bulk_subscribe_emails_error1">No usable email addresses were found#', $unusable['body']);
        self::assertStringContainsString('>nobody at example</textarea>', $unusable['body'], 'the input is kept');
        self::assertStringContainsString('value="7"', $unusable['body']);

        $unsubscribe = self::submitForm($client, '/bulk-unsubscribe', 'bulk_unsubscribe', ['bulk_unsubscribe[emails]' => '', 'bulk_unsubscribe[scope]' => 'bounce', 'bulk_unsubscribe[reason]' => 'Kept reason']);
        self::assertSame(422, $unsubscribe['status']);
        self::assertMatchesRegularExpression('#id="bulk_unsubscribe_emails_error1">Enter at least one email address#', $unsubscribe['body']);
        self::assertMatchesRegularExpression('#value="bounce" checked="checked"#', $unsubscribe['body'], 'the chosen scope is kept');
        self::assertStringContainsString('value="Kept reason"', $unsubscribe['body']);

        $import = self::submitForm($client, '/import', 'bulk_subscribe', []);
        self::assertSame(422, $import['status']);
        self::assertMatchesRegularExpression('#id="bulk_subscribe_file_error1">Choose a file to import#', $import['body']);

        $forged = self::submitForm($client, '/bulk-subscribe', 'bulk_subscribe', ['bulk_subscribe[csrf]' => 'forged', 'bulk_subscribe[emails]' => 'forged@example.com']);
        self::assertSame(422, $forged['status']);
        self::assertStringContainsString('CSRF token is invalid', $forged['body']);
        self::assertSame($subscribers, (string) self::value('SELECT COUNT(*) FROM subscribers'), 'nobody added');
    }

    /** Clearing a catto-mail delivery block: POST + CSRF, subscribers.manage, sending block only. */
    public function testClearingADeliveryBlockChangesOnlyTheBlock(): void
    {
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute(['smoke-blocked@ctnlist.test']);
        $id = (int) self::value('SELECT s_id FROM subscribers WHERE s_email = ?', ['smoke-blocked@ctnlist.test']);
        $uuid = (string) self::value('SELECT s_uuid FROM subscribers WHERE s_id = ?', [$id]);
        self::$db->prepare("UPDATE subscribers SET s_delivery_state = 'complained', s_delivery_state_at = ? WHERE s_id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        $memberships = self::rows('SELECT ls_l_id, ls_confirmed, ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? ORDER BY ls_l_id', [$id]);
        try {
            $admin = self::client();
            self::loginAsAdmin($admin);
            $page = self::request($admin, 'GET', '/subscribers/' . $uuid)['body'];
            self::assertStringContainsString('Spam complaint', $page);
            self::assertStringContainsString('does not restore list memberships or consent', $page);

            self::assertSame(403, self::request(self::client(), 'POST', '/subscribers/' . $uuid . '/delivery-ok', ['csrf' => 'x'])['status'], 'anonymous: refused');
            $forged = self::request($admin, 'POST', '/subscribers/' . $uuid . '/delivery-ok', ['csrf' => 'forged']);
            self::assertNotSame(302, $forged['status'], 'a forged token changes nothing');
            self::assertSame('complained', self::value('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$id]));

            $cleared = self::request($admin, 'POST', '/subscribers/' . $uuid . '/delivery-ok', ['csrf' => self::csrfToken($page)]);
            self::assertSame(302, $cleared['status']);
            self::assertStringContainsString('The complained block is cleared', self::request($admin, 'GET', '/subscribers/' . $uuid)['body']);
            self::assertSame('ok', self::value('SELECT s_delivery_state FROM subscribers WHERE s_id = ?', [$id]));
            self::assertSame($memberships, self::rows('SELECT ls_l_id, ls_confirmed, ls_unsubscribed FROM list_subscribers WHERE ls_s_id = ? ORDER BY ls_l_id', [$id]), 'memberships unchanged');
        } finally {
            self::$db->prepare("UPDATE subscribers SET s_delivery_state = 'ok', s_delivery_state_at = NULL WHERE s_id = ?")->execute([$id]);
        }
    }

    public function testExportAndSynchroniseOnlyChangeThingsOnPost(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $form = self::request($client, 'GET', '/export');
        self::assertSame(200, $form['status']);
        self::assertStringNotContainsString('export complete', $form['body'], 'showing the form writes nothing');

        $invalid = self::submitForm($client, '/export', 'export', ['export[limit]' => '0']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="export_limit_error1">Enter a whole number of at least 1#', $invalid['body']);
        $done = self::submitForm($client, '/export', 'export', ['export[limit]' => '5']);
        self::assertSame(200, $done['status']);
        self::assertStringContainsString('export-subscribers.txt - export complete', $done['body']);

        $sync = self::request($client, 'GET', '/sync');
        self::assertSame(200, $sync['status']);
        self::assertStringContainsString('action="/sync"', $sync['body']);
        self::assertSame(403, self::request($client, 'POST', '/sync', ['csrf' => 'forged'])['status'], 'CSRF required');
    }
}
