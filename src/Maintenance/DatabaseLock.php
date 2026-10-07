<?php

declare(strict_types=1);

namespace App\Maintenance;

use Doctrine\DBAL\Connection;

/**
 * Named "one at a time" locks shared by all PHP processes of an
 * installation: PostgreSQL session advisory locks, released at the end of
 * the run (or by the server when the process dies). Overlapping cron runs
 * of the catto-mail worker or the maintenance command skip instead of
 * competing; their individual effects are idempotent anyway.
 */
final class DatabaseLock
{
    public const CATTOMAIL_WORKER = 'ctnlist:cattomail:worker';
    public const MAINTENANCE = 'ctnlist:maintenance';

    public function __construct(private readonly Connection $db)
    {
    }

    public function acquire(string $name): bool
    {
        return (bool) $this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$name]);
    }

    public function release(string $name): void
    {
        $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$name]);
    }
}
