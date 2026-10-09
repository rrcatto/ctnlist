<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\CattoMail\CattoMailActivity;
use App\CattoMail\CattoMailClient;
use App\CattoMail\CattoMailConfig;
use App\Tests\Support\FakeCattoMail;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * Boots the kernel (test environment, ctnlist_test database) and runs each
 * test inside a transaction that is rolled back afterwards, so tests can
 * create any rows they need. Mail goes to the null transport and is
 * available to the mailer assertions.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    use MailerAssertionsTrait;

    protected Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = $this->service(Connection::class);
        // phpunit.dist.xml forces DB_NAME; a local override must never reach the development database.
        if ($this->db->getDatabase() !== 'ctnlist_test') {
            self::fail('Integration tests run against ctnlist_test only, not ' . $this->db->getDatabase() . '.');
        }
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Replace catto-mail's HTTP boundary with FakeCattoMail. Call it before
     * fetching any service that uses CattoMailClient.
     */
    protected function fakeCattoMail(): FakeCattoMail
    {
        $fake = new FakeCattoMail();
        self::getContainer()->set(CattoMailClient::class, new CattoMailClient(
            new MockHttpClient($fake->handler(), FakeCattoMail::BASE),
            $this->service(CattoMailConfig::class),
            new NullLogger(),
            static function (int $seconds): void {
            },
            $this->service(CattoMailActivity::class),
        ));
        return $fake;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);
        return $service;
    }

    /** Insert a subscriber (the insert trigger adds the ALL membership) and return its id. */
    protected function createSubscriber(string $email, string $firstName = '', string $lastName = ''): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO subscribers (s_email, s_fname, s_lname) VALUES (?, ?, ?) RETURNING s_id',
            [$email, $firstName, $lastName]
        );
    }

    protected function subscriberUuid(int $subscriberId): string
    {
        return (string) $this->db->fetchOne('SELECT s_uuid FROM subscribers WHERE s_id = ?', [$subscriberId]);
    }

    protected function listId(string $shortcode): int
    {
        return (int) $this->db->fetchOne('SELECT l_id FROM lists WHERE l_shortcode = ?', [$shortcode]);
    }

    protected function createList(string $shortcode, string $name): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO lists (l_shortcode, l_name) VALUES (?, ?) RETURNING l_id',
            [$shortcode, $name]
        );
    }

    /** Create or update a membership with the given consent state. */
    protected function setMembership(int $subscriberId, int $listId, bool $confirmed, bool $unsubscribed = false): void
    {
        $this->db->executeStatement(
            'INSERT INTO list_subscribers (ls_s_id, ls_l_id, ls_confirmed, ls_unsubscribed)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (ls_s_id, ls_l_id) DO UPDATE
             SET ls_confirmed = EXCLUDED.ls_confirmed, ls_unsubscribed = EXCLUDED.ls_unsubscribed',
            [$subscriberId, $listId, $confirmed, $unsubscribed],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]
        );
    }

    /**
     * Insert a message and return its MUID.
     *
     * @param list<int> $listIds
     */
    protected function createMessage(string $subject, array $listIds = [], int $maxSend = 1000000): string
    {
        $muid = bin2hex(random_bytes(16));
        $messageId = (int) $this->db->fetchOne(
            "INSERT INTO messages (m_uniqid, m_subject, m_html, m_text, m_max_send, m_from_name, m_from_address)
             VALUES (?, ?, '<p>Hello {firstname}</p>{unsubscribe}', 'Hello {firstname} {unsubscribe}', ?, 'Sender', 'sender@ctnlist.test')
             RETURNING m_id",
            [$muid, $subject, $maxSend]
        );
        foreach ($listIds as $listId) {
            $this->db->insert('message_lists', ['ml_m_id' => $messageId, 'ml_l_id' => $listId]);
        }
        return $muid;
    }
}
