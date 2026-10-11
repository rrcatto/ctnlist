<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mail;

use App\Config\SiteConfig;
use App\Log\SendLog;
use App\Mail\MailConnectionFactory;
use App\Mail\SmtpServerPool;
use App\Mail\TransactionalMailer;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Mime\Email;

final class MailerTest extends IntegrationTestCase
{
    public function testNotificationIsBlindCopiedAndLogged(): void
    {
        $sent = $this->service(TransactionalMailer::class)->sendNotification(
            'm1', 'UNSUBSCRIBE', '', 'jane@example.com', 'Jane', 'Bye', '<p>Bye</p>', 'Bye', null, 'news'
        );
        self::assertTrue($sent);
        self::assertEmailCount(1);
        $email = $this->email();
        self::assertSame('jane@example.com', $email->getTo()[0]->getAddress());
        self::assertNotEmpty($email->getBcc(), 'administrator copy');
        self::assertSame(['UNSUBSCRIBE', 'NEWS'], $this->lastSendLog());
    }

    public function testMagicLinkAndInvitation(): void
    {
        $mailer = $this->service(TransactionalMailer::class);
        $uuid = $this->subscriberUuid($this->createSubscriber('jane@example.com'));

        self::assertTrue($mailer->sendMagicLink('jane@example.com', $uuid, 'https://x/auth/verify?token=t', 1800));
        self::assertStringContainsString('expires in 30 minutes', (string) $this->email()->getTextBody());
        self::assertStringContainsString('then press Sign in on the page it opens. Opening the link alone does not sign you in.', (string) $this->email()->getTextBody(), 'the link opens a page; its button signs in');
        self::assertStringContainsString('sign-in link', (string) $this->email()->getSubject());
        self::assertSame(['MAGIC-LINK', ''], $this->lastSendLog());

        self::assertTrue($mailer->sendMagicLink('jane@example.com', $uuid, 'https://x/auth/verify?token=t', 1800, 'News & views'));
        self::assertSame('Confirm your subscription to News & views', $this->email(1)->getSubject(), 'says why it was sent');
        self::assertStringContainsString('confirm your subscription to News &amp; views', (string) $this->email(1)->getHtmlBody());
        self::assertStringNotContainsString('Sign in', (string) $this->email(1)->getTextBody());
        self::assertStringContainsString('press Continue on the page it opens, and then confirm', (string) $this->email(1)->getTextBody());
        self::assertSame(['MAGIC-LINK', ''], $this->lastSendLog(), 'the same secure sign-in link');

        self::assertTrue($mailer->sendListConfirmationInvitation('jane@example.com', $uuid, 'news', 'News'));
        self::assertStringContainsString('/confirm/' . $uuid . '/NEWS', (string) $this->email(2)->getTextBody());
        self::assertSame(['SUBSCRIBE', 'NEWS'], $this->lastSendLog());
    }

    /** The organisation's copy and the submitter's acknowledgement are separate mails, each in the Send Log. */
    public function testContactMailGoesToTheOrganisationAndToTheSubmitterSeparately(): void
    {
        $site = $this->service(SiteConfig::class);
        $muid = $this->createMessage('Offer');
        $mailer = $this->service(TransactionalMailer::class);

        self::assertTrue($mailer->sendContactNotice('visitor@example.com', 'Visitor', '<p>Internal</p>', 'Internal', '', $muid));
        self::assertTrue($mailer->sendContactAcknowledgement('visitor@example.com', 'Visitor', '<p>Thanks</p>', 'Thanks', '', $muid));

        $internal = array_values(array_unique([strtolower($site->contactEmail), strtolower($site->adminEmail)]));
        $notice = $this->email(0);
        self::assertSame($internal, array_map(static fn($a): string => strtolower($a->getAddress()), $notice->getTo()));
        self::assertSame('visitor@example.com', $notice->getReplyTo()[0]->getAddress());
        $acknowledgement = $this->email(1);
        self::assertSame('visitor@example.com', $acknowledgement->getTo()[0]->getAddress());
        self::assertCount(1, $acknowledgement->getTo());
        self::assertSame([], [...$acknowledgement->getCc(), ...$acknowledgement->getBcc()], 'no copies: the organisation has its own mail');
        self::assertSame('visitor@example.com - ' . $site->contactSubject, $acknowledgement->getSubject());
        self::assertSame([...$internal, 'visitor@example.com'], $this->db->fetchFirstColumn("SELECT LOWER(sl_email) FROM sendlog WHERE sl_type = 'CONTACT' AND sl_muid = ? ORDER BY sl_id", [$muid]));
    }

    /** Names and addresses typed into forms cannot add headers: Symfony Mime encodes them. */
    public function testFormInputCannotInjectHeaders(): void
    {
        $mailer = $this->service(TransactionalMailer::class);
        $mailer->sendContactAcknowledgement('jane@example.com', "Jane\r\nBcc: victim@example.net", '<p>Thanks</p>', 'Thanks', '', '');
        $mailer->sendContactNotice('jane@example.com', "Jane\r\nBcc: victim@example.net", '<p>Internal</p>', 'Internal', '', '');
        foreach (['To' => $this->email(0), 'Reply-To' => $this->email(1)] as $header => $email) {
            $raw = $email->toString();
            self::assertDoesNotMatchRegularExpression('/^Bcc: victim@example\.net/mi', $raw, 'no injected header line');
            self::assertMatchesRegularExpression('/^' . $header . ': .*Jane Bcc: victim@example\.net.*<jane@example\.com>/m', $raw, 'the line break became part of the name');
            self::assertStringNotContainsString('victim@example.net', implode(',', array_map(
                static fn($a): string => $a->getAddress(), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]
            )), 'not a recipient');
        }

        foreach (['sendContactAcknowledgement', 'sendContactNotice'] as $method) {
            try {
                $mailer->{$method}("jane@example.com\r\nBcc: victim@example.net", 'Jane', '<p>x</p>', 'x', '', '');
                self::fail($method . ': an address with a line break was accepted');
            } catch (\Symfony\Component\Mime\Exception\InvalidArgumentException) {
            }
        }
        self::assertEmailCount(2);
    }

    public function testFailedDeliveryIsNotLogged(): void
    {
        $site = $this->service(SiteConfig::class);
        $mailer = new TransactionalMailer(
            new SmtpServerPool('smtp://127.0.0.1:1'),
            $this->service(MailConnectionFactory::class),
            $this->service(SendLog::class),
            $site,
        );
        self::assertFalse($mailer->sendMagicLink('jane@example.com', '', 'https://x', 60));
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sendlog'));
    }

    private function email(int $index = 0): Email
    {
        $message = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $message);
        return $message;
    }

    /** @return array{string, string} type and list of the newest Send Log row */
    private function lastSendLog(): array
    {
        $row = $this->db->fetchAssociative('SELECT sl_type, sl_list_shortcode FROM sendlog ORDER BY sl_id DESC LIMIT 1');
        self::assertIsArray($row);
        return [(string) $row['sl_type'], (string) $row['sl_list_shortcode']];
    }
}
