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

        // A new message starts with an empty subject and content (placeholders only), laid out at the page's width.
        $new = self::request($client, 'GET', '/message')['body'];
        self::assertMatchesRegularExpression('#<input type="text" id="message_subject" name="message\[subject\]" required="required"[^>]*maxlength="250"[^>]*placeholder="The subject line of the email"#', $new);
        self::assertStringNotContainsString('Your subject here', $new);
        self::assertStringNotContainsString('Enter your message here', $new);
        self::assertMatchesRegularExpression('#<textarea id="message_html"[^>]*placeholder="Write the message here"[^>]*></textarea>#', $new, 'the HTML part starts empty');
        self::assertStringNotContainsString('lg:col-4">' . "\n" . '      <div class="card', $new, 'no sidebar');
        $previous = -1;
        foreach (['message_fromName', 'message_fromAddress', 'message_listIds_toggle', 'message_priority', 'message_maxSend', 'message_subject', 'message_templateId', 'message_html', 'message_text'] as $id) {
            $at = strpos($new, 'id="' . $id . '"');
            self::assertNotFalse($at, $id);
            self::assertGreaterThan($previous, $at, $id . ' comes after the field before it');
            $previous = $at;
        }
        self::assertMatchesRegularExpression('#<button class="form-control form-control-caret[^"]*" type="button" id="message_listIds_toggle"\s+data-bs-toggle="menu" data-bs-auto-close="outside"#', $new, 'the lists are a menu that stays open');
        self::assertMatchesRegularExpression('#<div class="menu app-checklist"[^>]*data-checklist[^>]*>\s*<div class="form-field"><input type="checkbox" id="message_listIds_\d+" name="message\[listIds\]\[\]" class="check"#', $new, 'of checkboxes');
        self::assertStringContainsString('data-checklist-summary>No lists selected</span>', $new);

        // A subject is required, and at most 250 characters; nothing else is.
        foreach (['' => 'Enter a subject', '   ' => 'Enter a subject', str_repeat('x', 251) => 'This value is too long'] as $bad => $error) {
            $refused = self::submitForm($client, '/message', 'message', ['message[subject]' => (string) $bad, 'message[html]' => '<p>Kept</p>'] + $fields);
            self::assertSame(422, $refused['status'], 'subject of ' . strlen((string) $bad) . ' characters');
            self::assertMatchesRegularExpression('#id="message_subject_error1">' . $error . '#', $refused['body']);
            self::assertStringContainsString('&lt;p&gt;Kept&lt;/p&gt;</textarea>', $refused['body'], 'what was entered is kept');
        }
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM messages WHERE m_html = '<p>Kept</p>'"), 'nothing saved without a valid subject');
        $long = str_repeat('s', 250 - strlen(self::$suffix)) . self::$suffix;
        self::assertStringContainsString('Message saved.', self::submitAndFollow($client, '/message', 'message', ['message[subject]' => $long, 'message[html]' => '', 'message[text]' => ''] + $fields));
        $blank = (string) self::scalar('SELECT m_uniqid FROM messages WHERE m_subject = ?', [$long]);
        self::assertSame(['', ''], [self::scalar('SELECT m_html FROM messages WHERE m_uniqid = ?', [$blank]), self::scalar('SELECT m_text FROM messages WHERE m_uniqid = ?', [$blank])], 'a blank draft is saved, whole subject and all');

        // The blank draft cannot be sent: no proof, no queueing.
        $sentBefore = self::scalar('SELECT COUNT(*) FROM sendlog');
        $proof = self::submitForm($client, '/sendtome/' . $blank, 'proof', ['proof[email]' => 'proof@example.com']);
        self::assertSame(422, $proof['status']);
        self::assertStringContainsString('The proof was not sent because the message cannot be sent yet: it is blank', $proof['body']);
        self::submitAndFollow($client, '/message/' . $blank, 'message', ['message[listIds][]' => [(string) self::scalar("SELECT l_id FROM lists WHERE l_shortcode = 'ALL'")]]);
        $queueForm = self::request($client, 'GET', '/queuelist/' . $blank)['body'];
        $queue = self::request($client, 'POST', '/queuelist', ['muid' => $blank, 'limit' => '500000', 'csrf' => self::csrfToken($queueForm, 'the queue form')]);
        self::assertSame(200, $queue['status']);
        self::assertStringContainsString('The message was not queued because it is blank', $queue['body']);
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM queue WHERE q_muid = ?', [$blank]));
        self::assertSame($sentBefore, self::scalar('SELECT COUNT(*) FROM sendlog'), 'nothing was sent');

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
        self::assertMatchesRegularExpression('#name="message\[listIds\]\[\]" class="check" value="' . $allId . '" checked#', $invalid['body'], 'the selected lists are kept');
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
