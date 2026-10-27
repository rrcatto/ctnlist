<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * Pages that htmx updates in part (App\Http\Htmx, templates/CLAUDE.md "htmx"): an htmx request for a
 * region gets that region alone, from the page's own address; every other request gets the page.
 */
final class HtmxRegionsTest extends SmokeTestCase
{
    public function testAPageAnswersItsRegionAndOtherwiseTheWholePage(): void
    {
        $email = 'smoke-htmx-region@ctnlist.test';
        self::$db->prepare('INSERT INTO subscribers (s_email) VALUES (?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([$email]);
        $client = self::client();
        self::loginAsAdmin($client);

        $region = self::region($client, '/subscribers?e=smoke-htmx-region&r=5', 'subscriber-results');
        self::assertRegion('subscriber-results', $region);
        self::assertStringContainsString($email, $region['body']);
        self::assertStringNotContainsString('Filter subscribers', $region['body'], 'not the search form');
        foreach (['HX-Request', 'HX-Request-Type', 'HX-Target'] as $header) {
            self::assertStringContainsString($header, $region['headers']['vary'] ?? '', "Vary: {$header}");
        }

        $page = self::request($client, 'GET', '/subscribers?e=smoke-htmx-region&r=5');
        self::assertStringContainsString('<html', $page['body']);
        self::assertStringContainsString('id="subscriber-results"', $page['body']);
        self::assertStringContainsString('hx-get="/subscribers"', $page['body'], 'the search form searches as you type');
        self::assertStringContainsString('HX-Request', $page['headers']['vary'] ?? '', 'pages vary too');

        // A page turn inside the region keeps to the region.
        self::assertMatchesRegularExpression('#<nav aria-label="Pagination" hx-boost:inherited="true" hx-target:inherited="\#subscriber-results"#',
            self::request($client, 'GET', '/subscribers?r=1')['body']);

        // htmx restoring history, an unknown region or a request that is not htmx's: the whole page.
        foreach ([['HX-Request: true', 'HX-Request-Type: partial', 'HX-Target: div#subscriber-results', 'HX-History-Restore-Request: true'],
                  ['HX-Request: true', 'HX-Request-Type: partial', 'HX-Target: div#no-such-region'],
                  ['HX-Request: true', 'HX-Request-Type: full', 'HX-Target: div#subscriber-results'],
                  ['HX-Request-Type: partial', 'HX-Target: div#subscriber-results']] as $headers) {
            curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
            $whole = self::request($client, 'GET', '/subscribers?e=smoke-htmx-region');
            curl_setopt($client, CURLOPT_HTTPHEADER, []);
            self::assertStringContainsString('<html', $whole['body'], implode(', ', $headers));
        }

        // htmx leaves authored message HTML alone: the editor draws it inside an hx-ignore row.
        foreach (['/message', '/template'] as $editor) {
            self::assertMatchesRegularExpression('#<div[^>]*hx-ignore[^>]*>\s*<label[^>]*>HTML part#', self::request($client, 'GET', $editor)['body'], $editor);
        }

        // Signed out, the region request gets the page's usual refusal (htmx then opens it as a page).
        self::assertSame(403, self::region(self::client(), '/subscribers', 'subscriber-results')['status']);
    }

    /** Every page with a search or a pager answers its results region, filtered as the page would be. */
    public function testEachSearchAnswersItsRegion(): void
    {
        $tag = bin2hex(random_bytes(3));
        $muid = bin2hex(random_bytes(16));
        self::$db->prepare("INSERT INTO messages (m_uniqid, m_subject, m_html, m_text) VALUES (?, ?, '<p>x</p>', 'x')")->execute([$muid, 'Smoke htmx subject ' . $tag]);
        self::$db->prepare("INSERT INTO templates (t_name, t_html, t_text) VALUES (?, '<p>{content}</p>', '{content}')")->execute(['Smoke htmx template ' . $tag]);
        self::$db->prepare("INSERT INTO sendlog (sl_type, sl_email) VALUES ('SMOKE-HTMX', ?)")->execute(['smoke-htmx-' . $tag . '@example.com']);
        $client = self::client();
        self::loginAsAdmin($client);

        $cases = [
            ['/messages?q=htmx+subject+' . $tag, 'message-results', 'Smoke htmx subject ' . $tag],
            ['/templates?q=htmx+template+' . $tag, 'template-results', 'Smoke htmx template ' . $tag],
            ['/sendlog?e=smoke-htmx-' . $tag, 'sendlog-results', 'smoke-htmx-' . $tag . '@example.com'],
            ['/sitelog?u=%2Fmessages', 'sitelog-results', '/messages'],
            ['/message-views/' . $muid . '?e=nobody', 'message-views-results', 'No matching subscriber has read this message yet.'],
            ['/roles?q=admin%40ctnlist', 'role-search-results', self::$admin['s_email']],
            ['/queue', 'queue-entries', 'nothing in the queue'],
            ['/lists', 'list-admin', 'Create a topic list'],
            ['/delivery', 'delivery-status', 'Pending work'],
            ['/delivery/webhooks?f=failed', 'webhook-events', 'No webhook events.'],
            ['/sending', 'queue-run', 'queue-run'],
        ];
        foreach ($cases as [$path, $region, $expected]) {
            $answer = self::region($client, $path, $region);
            self::assertRegion($region, $answer);
            self::assertStringContainsString($expected, $answer['body'], $path);
            self::assertCleanPage($path, $answer['body']);
        }
        self::assertStringContainsString('No message subject contains', self::region($client, '/messages?q=no-such-subject-' . $tag, 'message-results')['body']);
        self::assertStringNotContainsString('Smoke htmx template ' . $tag, self::region($client, '/templates?q=other-' . $tag, 'template-results')['body']);
    }

    /** A role's members can be searched, and Remove answers with the members region of the page it was pressed on. */
    public function testRoleMembersAreSearchedAndRemovedInPlace(): void
    {
        $key = 'smoke-htmx-' . bin2hex(random_bytes(3));
        $email = 'smoke-htmx-member@ctnlist.test';
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname) VALUES (?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([$email, 'Member']);
        self::$db->prepare('INSERT INTO roles (r_key, r_name) VALUES (?, ?)')->execute([$key, 'Smoke htmx role']);
        $roleId = (int) self::value('SELECT r_id FROM roles WHERE r_key = ?', [$key]);
        $memberId = (int) self::value('SELECT s_id FROM subscribers WHERE LOWER(s_email) = ?', [$email]);
        self::$db->prepare('INSERT INTO subscriber_roles (sr_s_id, sr_r_id) VALUES (?, ?), (?, ?)')->execute([$memberId, $roleId, self::$admin['s_id'], $roleId]);
        try {
            $client = self::client();
            self::loginAsAdmin($client);
            $page = '/roles/' . $roleId . '?q=htmx-member';
            $found = self::region($client, $page, 'role-members');
            self::assertRegion('role-members', $found);
            self::assertStringContainsString($email, $found['body']);
            self::assertStringNotContainsString(self::$admin['s_email'], $found['body'], 'only the members that match');

            $removed = self::region($client, '/roles/unassign', 'role-members', 'POST',
                ['csrf' => self::csrfToken($found['body']), 'subscriber_id' => (string) $memberId, 'role_id' => (string) $roleId, 'back' => '/roles/' . $roleId], $page);
            self::assertRegion('role-members', $removed);
            self::assertStringContainsString('Role removed from the subscriber.', $removed['body'], 'the flash message comes with the region');
            self::assertStringContainsString('No member matches', $removed['body'], 'the search of the page it was pressed on is kept');
            self::assertSame(0, (int) self::value('SELECT COUNT(*) FROM subscriber_roles WHERE sr_s_id = ? AND sr_r_id = ?', [$memberId, $roleId]));
        } finally {
            self::$db->prepare('DELETE FROM subscriber_roles WHERE sr_r_id = ?')->execute([$roleId]);
            self::$db->prepare('DELETE FROM roles WHERE r_id = ?')->execute([$roleId]);
        }
    }

    /** Creating a list in place: refused with the form's errors in the region (422), created with the list in it. */
    public function testListsAreCreatedInPlace(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $form = self::request($client, 'GET', '/lists')['body'];
        $fields = self::formFields($form, 'list', ['list[shortcode]' => 'bad code', 'list[name]' => 'Smoke htmx list']);
        $refused = self::region($client, '/lists', 'list-admin', 'POST', $fields, '/lists');
        self::assertRegion('list-admin', $refused, 422);
        self::assertStringContainsString('value="Smoke htmx list"', $refused['body'], 'what was typed is kept');

        $code = 'HX' . strtoupper(bin2hex(random_bytes(2)));
        try {
            $created = self::region($client, '/lists', 'list-admin', 'POST', ['list[shortcode]' => $code] + $fields, '/lists');
            self::assertRegion('list-admin', $created);
            self::assertStringContainsString("List {$code} created", $created['body']);
            self::assertStringContainsString('<code>' . $code . '</code>', $created['body']);
        } finally {
            self::$db->prepare('DELETE FROM lists WHERE l_shortcode = ?')->execute([$code]);
        }
    }

    /** A region that refreshes itself on a timer is not a visit for the Site Log; loading the page is. */
    public function testTimedRefreshesStayOutOfTheSiteLog(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $count = static fn(string $path): int => (int) self::value('SELECT COUNT(*) FROM sitelog WHERE stl_url = ?', [$path]);
        $before = $count('/sending');
        self::assertRegion('queue-run', self::region($client, '/sending', 'queue-run', source: 'div#queue-run'));
        self::assertRegion('queue-run', self::region($client, '/sending', 'queue-run', source: 'div#queue-run'));
        self::request($client, 'GET', '/sending');
        for ($i = 0; $i < 20 && $count('/sending') === $before; $i++) {
            usleep(100000); // the Site Log is written after the answer
        }
        usleep(300000);
        self::assertSame($before + 1, $count('/sending'), 'the page load only');
    }
}
