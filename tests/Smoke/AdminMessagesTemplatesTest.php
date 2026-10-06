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

        $page = self::submitAndFollow($client, '/template', 'template', ['template[name]' => $name, 'template[html]' => '<div>{content}</div>', 'template[text]' => '{content}']);
        self::assertStringContainsString('Template created.', $page);
        $id = (string) self::scalar('SELECT t_id FROM templates WHERE t_name = ?', [$name]);
        self::assertStringContainsString('/template/' . $id, $page, 'listed');

        $page = self::submitAndFollow($client, '/template/' . $id, 'template', ['template[name]' => $name . ' v2', 'template[html]' => '<p>{content}</p>', 'template[text]' => 'T']);
        self::assertStringContainsString('Template saved.', $page);
        self::assertSame('<p>{content}</p>', self::scalar('SELECT t_html FROM templates WHERE t_id = ?', [$id]));

        // Invalid input keeps everything entered, the HTML part included.
        $invalid = self::submitForm($client, '/template/' . $id, 'template', ['template[name]' => str_repeat('x', 101), 'template[html]' => '<p>Unsaved {content} &amp; more</p>', 'template[text]' => 'Unsaved text']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="template_name_error1">This value is too long#', $invalid['body']);
        self::assertStringContainsString('&lt;p&gt;Unsaved {content} &amp;amp; more&lt;/p&gt;</textarea>', $invalid['body']);
        self::assertStringContainsString('>Unsaved text</textarea>', $invalid['body']);
        self::assertSame('<p>{content}</p>', self::scalar('SELECT t_html FROM templates WHERE t_id = ?', [$id]), 'nothing saved');
        self::assertSame(404, self::request($client, 'GET', '/template/999999')['status']);
    }

    public function testDraftMessageWithoutListsThenAssigned(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $subject = 'Smoke message ' . self::$suffix;
        $fields = ['message[fromName]' => 'Smoke', 'message[fromAddress]' => 'smoke@ctnlist.test', 'message[subject]' => $subject,
            'message[html]' => '<p>Draft</p>', 'message[text]' => 'Draft', 'message[listIds][]' => []];

        $page = self::submitAndFollow($client, '/message', 'message', $fields);
        self::assertStringContainsString('Message saved.', $page);
        $muid = (string) self::scalar('SELECT m_uniqid FROM messages WHERE m_subject = ?', [$subject]);
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM message_lists ml JOIN messages m ON m.m_id = ml.ml_m_id WHERE m.m_uniqid = ?', [$muid]), 'no list, ALL not added');
        self::assertStringContainsString('<em>None selected</em>', $page);

        $allId = (string) self::scalar("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'");
        $form = self::request($client, 'GET', '/message/' . $muid)['body'];
        self::assertStringContainsString('value="' . $subject . '"', $form);
        self::submitAndFollow($client, '/message/' . $muid, 'message', ['message[listIds][]' => [$allId]]);
        self::assertSame('ALL', self::scalar('SELECT l.l_shortcode FROM message_lists ml JOIN lists l ON l.l_id = ml.ml_l_id JOIN messages m ON m.m_id = ml.ml_m_id WHERE m.m_uniqid = ?', [$muid]));

        // Invalid input: the editor again with the content and selections kept; nothing saved or sent.
        $sent = self::scalar('SELECT COUNT(*) FROM sendlog');
        $invalid = self::submitForm($client, '/message/' . $muid, 'message', [
            'message[html]' => '<p>Long unsaved body</p>', 'message[text]' => 'Unsaved text', 'message[fromAddress]' => 'not-an-address', 'message[maxSend]' => '-1',
        ]);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="message_fromAddress_error1">Enter a valid email address#', $invalid['body']);
        self::assertMatchesRegularExpression('#id="message_maxSend_error1">Enter a whole number from 0#', $invalid['body']);
        self::assertStringContainsString('&lt;p&gt;Long unsaved body&lt;/p&gt;</textarea>', $invalid['body'], 'the HTML part is not lost');
        self::assertStringContainsString('>Unsaved text</textarea>', $invalid['body']);
        self::assertMatchesRegularExpression('#name="message\[listIds\]\[\]" class="form-check-input" value="' . $allId . '" checked#', $invalid['body'], 'the selected lists are kept');
        self::assertSame('<p>Draft</p>', self::scalar('SELECT m_html FROM messages WHERE m_uniqid = ?', [$muid]), 'nothing saved');
        self::assertSame($sent, self::scalar('SELECT COUNT(*) FROM sendlog'), 'saving never sends');

        self::assertSame(404, self::request($client, 'GET', '/message/' . str_repeat('0', 32))['status']);
        self::assertStringContainsString('Message does not exist.', self::submitAndFollow($client, '/message', 'message', ['message[muid]' => str_repeat('0', 32)] + $fields));
    }

    /** @param list<string> $params */
    private static function scalar(string $sql, array $params = []): mixed
    {
        $statement = self::$db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }
}
