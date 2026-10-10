<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Subscriber\Engagement;
use App\Tests\Integration\IntegrationTestCase;

final class EngagementTest extends IntegrationTestCase
{
    /**
     * A contact link (+23456 per anonymous GET) can be reloaded without limit: the priority stops
     * at the column's maximum instead of overflowing, which would make every later update fail.
     */
    public function testPriorityStaysWithinTheColumn(): void
    {
        $id = $this->createSubscriber('jane@example.com');
        $uuid = $this->subscriberUuid($id);
        $this->db->executeStatement('UPDATE subscribers SET s_priority = 2147483000 WHERE s_id = ?', [$id]);
        $engagement = $this->service(Engagement::class);

        self::assertTrue($engagement->bump($uuid, 23456));
        self::assertSame(2147483647, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
        self::assertTrue($engagement->bump($uuid, 23456), 'still updatable at the maximum');
        self::assertTrue($engagement->set($uuid, PHP_INT_MAX));
        self::assertSame(2147483647, (int) $this->db->fetchOne('SELECT s_priority FROM subscribers WHERE s_id = ?', [$id]));
    }
}
