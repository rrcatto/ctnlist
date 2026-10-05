<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** Message and template administration. */
final class AdminMessagesTemplatesTest extends SmokeTestCase
{
    private static string $suffix;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$suffix = bin2hex(random_bytes(3));
    }

    public static function tearDownAfterClass(): void
    {
        self::$db->prepare('DELETE FROM messages WHERE m_subject LIKE ?')->execute(['Smoke message ' . self::$suffix . '%']);
        self::$db->prepare('DELETE FROM templates WHERE t_name LIKE ?')->execute(['Smoke template ' . self::$suffix . '%']);
        parent::tearDownAfterClass();
    }

    public function testTemplateCreateAndEdit(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $name = 'Smoke template ' . self::$suffix;

        $page = self::post($client, '/template', '/template', ['t_id' => '0', 't_name' => $name, 'mt_html' => '<div>{content}</div>', 't_text' => '{content}']);
        self::assertStringContainsString('Template created.', $page);
        $id = (string) self::scalar('SELECT t_id FROM templates WHERE t_name = ?', [$name]);
        self::assertStringContainsString('/template/' . $id, $page, 'listed');

        $page = self::post($client, '/template/' . $id, '/template', ['t_id' => $id, 't_name' => $name . ' v2', 'mt_html' => '<p>{content}</p>', 't_text' => 'T']);
        self::assertStringContainsString('Template saved.', $page);
        self::assertSame('<p>{content}</p>', self::scalar('SELECT t_html FROM templates WHERE t_id = ?', [$id]));
        self::assertSame(404, self::request($client, 'GET', '/template/999999')['status']);
    }

    public function testDraftMessageWithoutListsThenAssigned(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $subject = 'Smoke message ' . self::$suffix;
        $fields = ['m_uniqid' => '', 'm_t_id' => '0', 'm_from_name' => 'Smoke', 'm_from_address' => 'smoke@ctnlist.test',
            'm_subject' => $subject, 'm_priority' => '0', 'm_max_send' => '0', 'mt_html' => '<p>Draft</p>', 'm_text' => 'Draft'];

        $page = self::post($client, '/message', '/message', $fields);
        self::assertStringContainsString('Message saved.', $page);
        $muid = (string) self::scalar('SELECT m_uniqid FROM messages WHERE m_subject = ?', [$subject]);
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM message_lists ml JOIN messages m ON m.m_id = ml.ml_m_id WHERE m.m_uniqid = ?', [$muid]), 'no list, ALL not added');
        self::assertStringContainsString('<em>None selected</em>', $page);

        $allId = (string) self::scalar("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'");
        $form = self::request($client, 'GET', '/message/' . $muid)['body'];
        self::assertStringContainsString('value="' . $subject . '"', $form);
        self::post($client, '/message/' . $muid, '/message', ['m_uniqid' => $muid, 'list_ids[]' => $allId] + $fields);
        self::assertSame('ALL', self::scalar('SELECT l.l_shortcode FROM message_lists ml JOIN lists l ON l.l_id = ml.ml_l_id JOIN messages m ON m.m_id = ml.ml_m_id WHERE m.m_uniqid = ?', [$muid]));

        self::assertSame(404, self::request($client, 'GET', '/message/' . str_repeat('0', 32))['status']);
        self::assertStringContainsString('Message does not exist.', self::post($client, '/message', '/message', ['m_uniqid' => str_repeat('0', 32)] + $fields));
    }

    /**
     * Fetch $formPage for a CSRF token, POST $action, follow the redirect.
     *
     * @param array<string, string> $fields
     */
    private static function post(\CurlHandle $client, string $formPage, string $action, array $fields): string
    {
        $form = self::request($client, 'GET', $formPage)['body'];
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form, $match), $formPage);
        $response = self::request($client, 'POST', $action, $fields + ['csrf' => html_entity_decode($match[1])]);
        self::assertSame(302, $response['status'], "POST {$action}");
        $page = self::request($client, 'GET', (string) parse_url($response['location'], PHP_URL_PATH))['body'];
        self::assertCleanPage($action, $page);
        return $page;
    }

    /** @param list<string> $params */
    private static function scalar(string $sql, array $params = []): mixed
    {
        $statement = self::$db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
