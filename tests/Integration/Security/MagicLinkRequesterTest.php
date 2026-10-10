<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\MagicLinkRequester;
use App\Security\SubscriberUserProvider;
use App\Subscriber\ProfileService;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Mime\Email;

final class MagicLinkRequesterTest extends IntegrationTestCase
{
    public function testNewAddressGetsAnIdentityAndIsSentOnToConfirmAll(): void
    {
        self::assertTrue($this->service(MagicLinkRequester::class)->request(' New@Example.com '));

        $identity = $this->db->fetchAssociative("SELECT s_id FROM subscribers WHERE s_email = 'new@example.com'");
        self::assertIsArray($identity, 'identity created');
        self::assertFalse((bool) $this->db->fetchOne('SELECT ls_confirmed FROM list_subscribers WHERE ls_s_id = ?', [$identity['s_id']]), 'no consent granted');
        $token = $this->db->fetchAssociative('SELECT alt_email, alt_return_action, alt_return_l_id FROM auth_login_tokens WHERE alt_s_id = ?', [$identity['s_id']]);
        self::assertSame(['alt_email' => 'new@example.com', 'alt_return_action' => 'confirm', 'alt_return_l_id' => $this->listId('ALL')], $token);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertMatchesRegularExpression('#/auth/verify\?token=[A-Za-z0-9_-]{43}#', (string) $email->getTextBody());
        self::assertSame('MAGIC-LINK', $this->db->fetchOne('SELECT sl_type FROM sendlog ORDER BY sl_id DESC LIMIT 1'));
    }

    public function testKnownSubscriberKeepsTheRequestedReturnAction(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $requester = $this->service(MagicLinkRequester::class);

        self::assertTrue($requester->request('jane@example.com', 'messages'));
        self::assertTrue($requester->request('jane@example.com', 'not-an-action', 5, 6));
        self::assertSame(
            [['alt_return_action' => 'messages', 'alt_return_l_id' => null], ['alt_return_action' => 'profile', 'alt_return_l_id' => null]],
            $this->db->fetchAllAssociative('SELECT alt_return_action, alt_return_l_id FROM auth_login_tokens WHERE alt_s_id = ? ORDER BY alt_id', [$id]),
            'unknown actions fall back to the profile without context'
        );
    }

    /** The email says why it was sent: confirming a list (subscribe form, consent link) or signing in. */
    public function testEmailWordingFollowsThePurpose(): void
    {
        $news = $this->createList('NEWS', 'News');
        $this->createSubscriber('jane@example.com');
        $requester = $this->service(MagicLinkRequester::class);

        self::assertTrue($requester->request('jane@example.com', 'confirm', null, $news));
        $confirm = $this->getMailerMessage(0);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $confirm);
        self::assertSame('Confirm your subscription to News', $confirm->getSubject());
        self::assertTrue($requester->request('jane@example.com', 'messages'));
        $signIn = $this->getMailerMessage(1);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $signIn);
        self::assertStringEndsWith('sign-in link', (string) $signIn->getSubject());
    }

    public function testRateLimitAndUnusableAddresses(): void
    {
        $requester = $this->service(MagicLinkRequester::class);
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($requester->request('busy@example.com'));
        }
        self::assertFalse($requester->request('busy@example.com'), 'AUTH_MAGIC_LINK_MAX_PER_EMAIL reached');
        self::assertFalse($requester->request('not-an-address'));
        self::assertFalse($requester->request('postmaster@example.com'), 'unusable under the v5 rules');
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM subscribers WHERE s_email LIKE '%postmaster%'"));
    }

    /**
     * The v5 cleanup rules turn some typed addresses into someone else's (a subdomain of an
     * attacker's domain, a "mailto" prefix): the link signs in that identity, so it must only
     * ever reach that identity's own mailbox, never the address that was typed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rewrittenAddresses')]
    public function testTheLinkGoesOnlyToTheIdentitysOwnAddress(string $typed): void
    {
        $id = $this->createSubscriber('alice@gmail.com');

        self::assertTrue($this->service(MagicLinkRequester::class)->request($typed));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['alice@gmail.com'], array_map(static fn(\Symfony\Component\Mime\Address $a): string => $a->getAddress(), $email->getTo()));
        self::assertSame(['alt_s_id' => $id, 'alt_email' => 'alice@gmail.com'],
            $this->db->fetchAssociative('SELECT alt_s_id, alt_email FROM auth_login_tokens ORDER BY alt_id DESC LIMIT 1'));
    }

    /** @return iterable<string, array{string}> */
    public static function rewrittenAddresses(): iterable
    {
        yield 'subdomain of another domain' => ['alice@gmail.attacker.example'];
        yield 'mailto prefix' => ['mailtoalice@gmail.com'];
        yield 'www prefix' => ['www.alice@gmail.com'];
    }

    /** Over the per-IP limit nothing is created: no subscriber, no membership, no consent event. */
    public function testRateLimitedRequestsCreateNoIdentity(): void
    {
        $owner = $this->createSubscriber('earlier@example.com');
        $now = time();
        $tokens = $this->service(\App\Repository\AuthLoginTokenRepository::class);
        for ($i = 0; $i < 20; $i++) {
            $tokens->create($owner, 'earlier@example.com', hash('sha256', 'flood' . $i), date('Y-m-d H:i:s', $now - 60), date('Y-m-d H:i:s', $now + 600),
                '203.0.113.9', 'test', 'profile', null, null);
        }
        $this->service(\Symfony\Component\HttpFoundation\RequestStack::class)
            ->push(\Symfony\Component\HttpFoundation\Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));

        self::assertFalse($this->service(MagicLinkRequester::class)->request('flood-new@example.com'));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM subscribers WHERE s_email = 'flood-new@example.com'"));
        self::assertEmailCount(0);
    }

    public function testProfileUpdateIsStoredAndNotified(): void
    {
        $id = $this->createSubscriber('jane@example.com', 'Jane');
        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($id);

        $this->service(ProfileService::class)->update($user, [
            's_fname' => '  Janet ', 's_lname' => str_repeat('x', 150), 's_birthday' => '1990-02-30x', 's_country' => 'Zambia', 's_email' => 'ignored@example.com',
        ]);

        $row = $this->db->fetchAssociative('SELECT s_fname, LENGTH(s_lname) AS lname_length, s_birthday, s_country, s_email FROM subscribers WHERE s_id = ?', [$id]);
        self::assertSame(['s_fname' => 'Janet', 'lname_length' => 100, 's_birthday' => null, 's_country' => 'Zambia', 's_email' => 'jane@example.com'], $row);
        self::assertSame('UPDATE-USER', $this->db->fetchOne('SELECT sl_type FROM sendlog ORDER BY sl_id DESC LIMIT 1'));
        self::assertEmailCount(1);
    }
}
