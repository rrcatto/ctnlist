<?php

declare(strict_types=1);

final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private string $ip;
    private string $agent;

    public function __construct(private \DB\SQL $db, Base $fat)
    {
        $this->ip = mb_substr(trim((string) $fat->get('IP')), 0, 45);
        $this->agent = mb_substr(trim((string) $fat->get('AGENT')), 0, 500);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $rows = $this->db->exec(
            'SELECT ses_data FROM sessions WHERE ses_id = :id',
            [':id' => $id]
        );
        return isset($rows[0]['ses_data']) ? (string) $rows[0]['ses_data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->db->exec(
            'INSERT INTO sessions (ses_id, ses_data, ses_ip, ses_agent, ses_stamp)
             VALUES (:id, :data, :ip, :agent, :stamp)
             ON CONFLICT (ses_id) DO UPDATE
             SET ses_data = EXCLUDED.ses_data,
                 ses_ip = EXCLUDED.ses_ip,
                 ses_agent = EXCLUDED.ses_agent,
                 ses_stamp = EXCLUDED.ses_stamp',
            [
                ':id' => $id,
                ':data' => $data,
                ':ip' => $this->ip,
                ':agent' => $this->agent,
                ':stamp' => time(),
            ]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->exec('DELETE FROM sessions WHERE ses_id = :id', [':id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $this->db->exec(
            'DELETE FROM sessions WHERE ses_stamp < :cutoff',
            [':cutoff' => time() - $max_lifetime]
        );
        return 1;
    }
}