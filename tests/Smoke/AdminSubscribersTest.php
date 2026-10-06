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
