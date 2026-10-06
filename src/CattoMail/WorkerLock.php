<?php

declare(strict_types=1);

namespace App\CattoMail;

use Doctrine\DBAL\Connection;

/**
 * One catto-mail worker at a time per database: a PostgreSQL session
 * advisory lock, released at the end of the run (or by the server when the
 * process dies). Overlapping cron runs skip instead of competing; every
 * individual effect is idempotent anyway, so this is about not doing the
 * same work twice, not about correctness.
 */
final class WorkerLock
{
    private const KEY = 'ctnlist:cattomail:worker';

    public function __construct(private readonly Connection $db)
    {
    }

    public function acquire(): bool
    {
        return (bool) $this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [self::KEY]);
    }

    public function release(): void
    {
        $this->db->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [self::KEY]);
    }
}
