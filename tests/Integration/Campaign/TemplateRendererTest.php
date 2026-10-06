<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Campaign\TemplateRenderer;
use App\Repository\MessageRepository;
use App\Repository\SubscriberRepository;
use App\Tests\Integration\IntegrationTestCase;

/** Behaviour of the v5 placeholder merge (verified identical to v5 MergeTemplate when ported). */
final class TemplateRendererTest extends IntegrationTestCase
{
    public function testSubscriberRenderingWithTemplateAndListContext(): void
    {
        $templateId = (int) $this->db->fetchOne("INSERT INTO templates (t_name, t_html, t_text) VALUES ('t', '<main>{content}</main>', 'T {content}') RETURNING t_id");
        $muid = $this->createMessage('Hi');
        $this->db->executeStatement('UPDATE messages SET m_t_id = ?, m_html = ?, m_text = ? WHERE m_uniqid = ?', [$templateId, '<p>{FirstName} {unsubscribe} {confirm} {subscription}</p>', '{firstname}', $muid]);
        $subscriberId = $this->createSubscriber('jane@example.com', 'Jane');
        $this->db->executeStatement('UPDATE subscribers SET s_emailsleft = 10 WHERE s_id = ?', [$subscriberId]);
        $uuid = $this->subscriberUuid($subscriberId);

        $rendered = $this->render($muid, $uuid, 'news');
        self::assertStringStartsWith('<main><p>Jane <a href="https://ctnlist.test/unsubscribe/' . $uuid . '/NEWS/' . $muid . '">UNSUBSCRIBE</a>', $rendered->html);
        self::assertStringContainsString('confirm/' . $uuid . '/NEWS/' . $muid, $rendered->html);
        self::assertStringContainsString('Manage your list subscriptions: <a href="https://ctnlist.test/profile/subscriber/' . $uuid . '">UPDATE</a>', $rendered->html, 'normal subscription message, placeholders inside it filled');
        self::assertSame('T Jane', $rendered->text);
    }

    public function testNoListContextAndConfirmationThreshold(): void
    {
        $muid = $this->createMessage('Hi');
        $this->db->executeStatement('UPDATE messages SET m_html = ? WHERE m_uniqid = ?', ['{unsubscribe}|{confirm}|{subscription}', $muid]);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));

        $html = $this->render($muid, $uuid, '')->html;
        self::assertStringStartsWith('UNSUBSCRIBE|OPT IN|Confirm your list subscription: OPT IN', $html, 's_emailsleft 0 is at the confirmation level');
    }

    public function testAnonymousRenderingForArchives(): void
    {
        $muid = $this->createMessage('Hi');
        $this->db->executeStatement('UPDATE messages SET m_html = ?, m_a_id = 7 WHERE m_uniqid = ?', ['{firstname}|{unsubscribe}|{archive}|{booking}|{suid}', $muid]);

        $html = $this->render($muid, null, '')->html;
        self::assertSame('|UNSUBSCRIBE|<a href="https://ctnlist.test/archive/7">ARCHIVE</a>|BOOKING FORM|', $html, 'a plain label, not the raw placeholder or a personal link');
    }

    /**
     * Every supported placeholder, for a subscriber and anonymously (archive):
     * none is ever left in the output, and the anonymous copy carries no
     * subscriber identifier.
     */
    public function testEveryPlaceholderIsReplacedInBothRenderings(): void
    {
        $placeholders = ['{advertise}', '{facebook}', '{twitter}', '{STORE}', '{lms-booking}', '{subscribe}', '{listname}', '{domain}', '{organisation}',
            '{archive}', '{subscription}', '{unsubscribe}', '{forward}', '{preferences}', '{booking}', '{contact}', '{firstname}', '{lastname}',
            '{emailsleft}', '{confirm}', '{like}', '{dislike}', '{usertrack}', '{baseurl}', '{listshortcode}', '{muid}', '{suid}'];
        $muid = $this->createMessage('Hi');
        $body = implode('|', $placeholders);
        $this->db->executeStatement('UPDATE messages SET m_html = ?, m_text = ?, m_a_id = 7 WHERE m_uniqid = ?', [$body, $body, $muid]);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com', 'Jane'));

        foreach (['subscriber' => $this->render($muid, $uuid, 'NEWS'), 'archive' => $this->render($muid, null, '')] as $kind => $rendered) {
            foreach ([$rendered->html, $rendered->text] as $output) {
                self::assertDoesNotMatchRegularExpression('/\{[a-z-]+\}/i', $output, "{$kind}: no placeholder left");
            }
        }
        $archive = $this->render($muid, null, '');
        self::assertStringNotContainsString($uuid, $archive->html . $archive->text);
        self::assertStringContainsString('|BOOKING FORM|CONTACT FORM|', $archive->html);
        self::assertStringContainsString('|https://ctnlist.test/|', $archive->html, '{baseurl}');
        self::assertStringStartsWith('||||', $archive->html, 'unconfigured site links and retired placeholders render nothing');
    }

    public function testNamesAreTextInTheHtmlPart(): void
    {
        $muid = $this->createMessage('Hi');
        $this->db->executeStatement('UPDATE messages SET m_html = ?, m_text = ? WHERE m_uniqid = ?', ['<p>{firstname} {lastname}</p>', '{firstname} {lastname}', $muid]);
        $id = $this->createSubscriber('jane@example.com', '<b>Jane</b>');
        $this->db->executeStatement("UPDATE subscribers SET s_lname = 'O''Brien & Sons' WHERE s_id = ?", [$id]);

        $rendered = $this->render($muid, $this->subscriberUuid($id), '');
        self::assertSame('<p>&lt;b&gt;Jane&lt;/b&gt; O&#039;Brien &amp; Sons</p>', $rendered->html);
        self::assertSame("<b>Jane</b> O'Brien & Sons", $rendered->text, 'the text part is plain text');
    }

    public function testRetiredPlaceholdersRenderNothing(): void
    {
        $muid = $this->createMessage('Hi');
        $this->db->executeStatement('UPDATE messages SET m_html = ?, m_text = ? WHERE m_uniqid = ?', ['<p>Shop: {STORE}{lms-booking}.</p>', 'Shop: {store}{LMS-booking}.', $muid]);

        $rendered = $this->render($muid, null, '');
        self::assertSame('<p>Shop: .</p>', $rendered->html);
        self::assertSame('Shop: .', $rendered->text);
    }

    /** Proof copies have no subscriber: subscriber links lead to /proof-link, no tracking pixel, empty {suid}. */
    public function testProofLinksAreExplicit(): void
    {
        $news = $this->createList('NEWS', 'News');
        $muid = $this->createMessage('Hi', [$news]);
        $this->db->executeStatement('UPDATE messages SET m_html = ? WHERE m_uniqid = ?', ['{firstname}|{unsubscribe}|{forward}|{preferences}|{confirm}|{like}|{dislike}|{usertrack}|{suid}|{subscribe}', $muid]);
        $message = $this->service(MessageRepository::class)->findByMuid($muid);
        self::assertNotNull($message);

        $html = $this->service(TemplateRenderer::class)->renderProof($message, 'NEWS')->html;
        $proof = '<a href="https://ctnlist.test/proof-link">';
        self::assertSame('Test|' . $proof . 'UNSUBSCRIBE</a>|' . $proof . 'FORWARD</a>|' . $proof . 'UPDATE</a>|' . $proof . 'YES</a>|' . $proof . 'YES</a>|' . $proof . 'NO</a>|||'
            . '<a href="https://ctnlist.test/subscribe?m=' . $muid . '&amp;l=NEWS">SUBSCRIBE</a>', $html, 'the public subscribe link stays real');
        self::assertStringNotContainsString(TemplateRenderer::PROOF_RECIPIENT['s_uuid'], $html);
    }

    private function render(string $muid, ?string $uuid, string $list): \App\Campaign\RenderedMessage
    {
        $message = $this->service(MessageRepository::class)->findByMuid($muid);
        self::assertNotNull($message);
        $recipient = $uuid === null ? null : $this->service(SubscriberRepository::class)->findRecipientByUuid($uuid);
        return $this->service(TemplateRenderer::class)->render($message, $recipient, $list);
    }
}
