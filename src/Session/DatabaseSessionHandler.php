<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Application-owned PHP sessions in the `sessions` table (managed by Phinx),
 * recording the client IP and user agent of the last write.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $data = $this->db->fetchOne('SELECT ses_data FROM sessions WHERE ses_id = ?', [$id]);
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $request = $this->requestStack->getMainRequest();
        $this->db->executeStatement(
            'INSERT INTO sessions (ses_id, ses_data, ses_ip, ses_agent, ses_stamp)
             VALUES (:id, :data, :ip, :agent, :stamp)
             ON CONFLICT (ses_id) DO UPDATE
             SET ses_data = EXCLUDED.ses_data,
                 ses_ip = EXCLUDED.ses_ip,
                 ses_agent = EXCLUDED.ses_agent,
                 ses_stamp = EXCLUDED.ses_stamp',
            [
                'id' => $id,
                'data' => $data,
                'ip' => mb_substr((string) $request?->getClientIp(), 0, 45),
                'agent' => mb_substr((string) $request?->headers->get('User-Agent'), 0, 500),
                'stamp' => time(),
            ]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->executeStatement('DELETE FROM sessions WHERE ses_id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int
    {
        return $this->db->executeStatement('DELETE FROM sessions WHERE ses_stamp < ?', [time() - $max_lifetime]);
    }
}
