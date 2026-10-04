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
        self::assertStringStartsWith('<main><p>Jane <a href="http://localhost:8180/unsubscribe/' . $uuid . '/NEWS/' . $muid . '">UNSUBSCRIBE</a>', $rendered->html);
        self::assertStringContainsString('confirm/' . $uuid . '/NEWS/' . $muid, $rendered->html);
        self::assertStringContainsString('Manage your list subscriptions: <a href="http://localhost:8180/profile/subscriber/' . $uuid . '">UPDATE</a>', $rendered->html, 'normal subscription message, placeholders inside it filled');
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
        self::assertSame('|UNSUBSCRIBE|<a href="http://localhost:8180/archive/7">ARCHIVE</a>|{booking}|', $html, 'v5 leaves {booking} in archives');
    }

    private function render(string $muid, ?string $uuid, string $list): \App\Campaign\RenderedMessage
    {
        $message = $this->service(MessageRepository::class)->findByMuid($muid);
        self::assertNotNull($message);
        $recipient = $uuid === null ? null : $this->service(SubscriberRepository::class)->findRecipientByUuid($uuid);
        return $this->service(TemplateRenderer::class)->render($message, $recipient, $list);
    }
}
