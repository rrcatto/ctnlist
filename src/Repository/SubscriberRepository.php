<?php

declare(strict_types=1);

namespace App\Repository;

use App\Subscriber\EmailNormaliser;
use Doctrine\DBAL\Connection;

/**
 * Subscriber identities (`subscribers`). A subscriber row is an identity only;
 * list consent lives in `list_subscribers`.
 *
 * @phpstan-type Identity array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string}
 * @phpstan-type Recipient array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string,
 *     s_emailsleft: int, s_priority: int, s_last_interacted: ?string, s_delivery_state: string}
 */
final class SubscriberRepository
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return Identity|null */
    public function findIdentityById(int $id): ?array
    {
        return $this->identity($this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE s_id = ?',
            [$id]
        ));
    }

    /** @return Identity|null */
    public function findIdentityByUuid(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match(self::UUID_PATTERN, $uuid)) {
            return null;
        }
        return $this->identity($this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE s_uuid = ?',
            [$uuid]
        ));
    }

    /** @return Identity|null the subscriber with this address after the v5 cleanup rules */
    public function findIdentityByEmail(string $email): ?array
    {
        return $this->identity($this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname FROM subscribers WHERE LOWER(s_email) = ?',
            [EmailNormaliser::correct($email)]
        ));
    }

    /**
     * Update engagement fields: bounces reset, emails-left set, priority set
     * or (with $increment) raised, last interaction set (null clears it).
     */
    public function updateEngagement(string $uuid, int $priority, bool $increment, int $emailsLeft, ?string $lastInteracted): bool
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match(self::UUID_PATTERN, $uuid)) {
            return false;
        }
        // Kept within the INTEGER column: an anonymous contact link can be reloaded without limit,
        // and an out-of-range value would make every later update of this subscriber fail.
        $value = $increment ? 's_priority::BIGINT + CAST(:priority AS BIGINT)' : 'CAST(:priority AS BIGINT)';
        return $this->db->executeStatement(
            'UPDATE subscribers
             SET s_priority = LEAST(GREATEST(' . $value . ', -2147483648), 2147483647),
                 s_bounces = 0, s_emailsleft = :left, s_last_interacted = :interacted
             WHERE s_uuid = :uuid',
            ['priority' => $priority, 'left' => $emailsLeft, 'interacted' => $lastInteracted, 'uuid' => $uuid]
        ) === 1;
    }

    /** @return Recipient|null */
    public function findRecipientByUuid(string $uuid): ?array
    {
        $uuid = strtolower(trim($uuid));
        return preg_match(self::UUID_PATTERN, $uuid) ? $this->recipient('s_uuid = ?', $uuid) : null;
    }

    /** @return Recipient|null the subscriber with this address after the v5 cleanup rules */
    public function findRecipientByEmail(string $email): ?array
    {
        return $this->recipient('LOWER(s_email) = ?', EmailNormaliser::correct($email));
    }

    /**
     * The subscriber for this address (after the v5 cleanup rules), created
     * if needed. A new identity grants no list consent (the insert trigger
     * adds an unconfirmed ALL membership and the subscriber role).
     *
     * @return array{identity: Identity, created: bool}|null null for an unusable address
     */
    public function findOrCreateIdentity(string $email, string $now): ?array
    {
        $email = EmailNormaliser::correct($email);
        if (!EmailNormaliser::isValid($email)) {
            return null;
        }
        $created = $this->db->executeStatement(
            'INSERT INTO subscribers (s_email, s_created_at) VALUES (?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING',
            [$email, $now]
        ) === 1;
        $identity = $this->findIdentityByEmail($email);
        return $identity === null ? null : ['identity' => $identity, 'created' => $created];
    }

    /** @return array<string, ?string>|null the profile fields, as stored */
    public function profile(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT s_uuid, s_email, s_created_at, s_fname, s_lname, s_phone, s_birthday, s_gender, s_province,
                    s_country, s_business, s_url
             FROM subscribers WHERE s_id = ?',
            [$id]
        );
        return $row === false ? null : array_map(static fn(mixed $v): ?string => $v === null ? null : (string) $v, $row);
    }

    /** @param array<string, ?string> $fields subscriber-editable profile columns */
    public function updateProfile(int $id, array $fields): void
    {
        $this->db->update('subscribers', $fields, ['s_id' => $id]);
    }

    /** Raise the priority to at least $priority (never lowers it). */
    public function raisePriority(int $id, int $priority): void
    {
        $this->db->executeStatement('UPDATE subscribers SET s_priority = GREATEST(s_priority, ?) WHERE s_id = ?', [$priority, $id]);
    }

    /** Subscribers matching the administrator's list filters. */
    public function reportCount(string $email, bool $activeOnly, bool $includeUnsubscribed, int $listId): int
    {
        [$where, $params] = self::reportFilter($email, $activeOnly, $includeUnsubscribed, $listId);
        return (int) $this->db->fetchOne('SELECT COUNT(DISTINCT s.s_id) FROM subscribers s ' . $where, $params);
    }

    /**
     * A page of the administrator's subscriber list: engaged first, with every
     * membership as a list name and state (confirmed, unsubscribed or
     * pending), ordered by list name.
     *
     * @return list<array<string, mixed>> rows; `memberships` is a list<array{name: string, state: string}>
     */
    public function reportPage(string $email, bool $activeOnly, bool $includeUnsubscribed, int $listId, int $offset, int $limit): array
    {
        [$where, $params] = self::reportFilter($email, $activeOnly, $includeUnsubscribed, $listId);
        $rows = $this->db->fetchAllAssociative(
            "SELECT s.s_id, s.s_uuid, s.s_email, s.s_fname, s.s_lname, s.s_priority, s.s_last_interacted, s.s_bounces, s.s_emailsleft,
                    COALESCE(JSON_AGG(JSON_BUILD_OBJECT('name', l.l_name, 'state', CASE
                        WHEN ls.ls_confirmed AND NOT ls.ls_unsubscribed THEN 'confirmed'
                        WHEN ls.ls_unsubscribed THEN 'unsubscribed'
                        ELSE 'pending' END) ORDER BY l.l_name) FILTER (WHERE l.l_id IS NOT NULL), '[]') AS memberships
             FROM subscribers s
             LEFT JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             LEFT JOIN lists l ON l.l_id = ls.ls_l_id
             {$where}
             GROUP BY s.s_id
             ORDER BY (s.s_last_interacted IS NULL) ASC, s.s_last_interacted DESC, s.s_priority DESC, s.s_email ASC
             LIMIT " . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );
        foreach ($rows as &$row) {
            $row['memberships'] = array_map(
                static fn(array $m): array => ['name' => (string) $m['name'], 'state' => (string) $m['state']],
                (array) json_decode((string) $row['memberships'], true, flags: JSON_THROW_ON_ERROR)
            );
        }
        return $rows;
    }

    /**
     * The v5 export lists: eligible addresses (confirmed, subscribed, active
     * list), or for removal those with an unsubscribed membership and no
     * eligible one; optionally limited to one list.
     *
     * @return list<string>
     */
    public function exportEmails(bool $eligible, int $offset, int $limit, int $listId): array
    {
        $params = ['offset' => max(0, $offset), 'limit' => max(1, min(10000000, $limit))];
        $list = '';
        if ($listId > 0) {
            $list = ' AND ls.ls_l_id = :list';
            $params['list'] = $listId;
        }
        $sql = $eligible
            ? "SELECT DISTINCT s.s_email FROM subscribers s
               JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
               JOIN lists l ON l.l_id = ls.ls_l_id
               WHERE ls.ls_confirmed = TRUE AND ls.ls_unsubscribed = FALSE AND l.l_active = TRUE{$list}"
            : "SELECT DISTINCT s.s_email FROM subscribers s
               JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
               WHERE ls.ls_unsubscribed = TRUE{$list}
                 AND NOT EXISTS (
                     SELECT 1 FROM list_subscribers active_ls JOIN lists active_l ON active_l.l_id = active_ls.ls_l_id
                     WHERE active_ls.ls_s_id = s.s_id AND active_ls.ls_confirmed = TRUE
                       AND active_ls.ls_unsubscribed = FALSE AND active_l.l_active = TRUE)";
        return array_map('strval', $this->db->fetchFirstColumn($sql . ' ORDER BY s.s_email LIMIT :limit OFFSET :offset', $params));
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function reportFilter(string $email, bool $activeOnly, bool $includeUnsubscribed, int $listId): array
    {
        $clauses = [];
        $params = [];
        if (trim($email) !== '') {
            $clauses[] = 'LOWER(s.s_email) LIKE LOWER(:email)';
            $params['email'] = Like::contains($email);
        }
        if ($activeOnly) {
            $clauses[] = 's.s_last_interacted IS NOT NULL';
        }
        if (!$includeUnsubscribed) {
            $clauses[] = 'EXISTS (SELECT 1 FROM list_subscribers active_ls JOIN lists active_l ON active_l.l_id = active_ls.ls_l_id
                WHERE active_ls.ls_s_id = s.s_id AND active_ls.ls_confirmed = TRUE AND active_ls.ls_unsubscribed = FALSE AND active_l.l_active = TRUE)';
        }
        if ($listId > 0) {
            $clauses[] = 'EXISTS (SELECT 1 FROM list_subscribers filter_ls WHERE filter_ls.ls_s_id = s.s_id AND filter_ls.ls_l_id = :list)';
            $params['list'] = $listId;
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** v5 queue-time state: bounces and priority reset, one fewer email left (not below zero). */
    public function markQueued(int $id): void
    {
        $this->db->executeStatement(
            'UPDATE subscribers SET s_bounces = 0, s_priority = 0, s_emailsleft = GREATEST(0, s_emailsleft - 1) WHERE s_id = ?',
            [$id]
        );
    }

    /**
     * Find subscribers for a picker: email, first or last name containing the
     * term (case-insensitive), or the exact UUID. Returns at most $limit rows.
     *
     * @return list<array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string}>
     */
    public function search(string $term, int $limit): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $uuid = strtolower($term);
        $like = Like::contains($term);
        return array_map(static fn(array $row): array => [
            's_id' => (int) $row['s_id'],
            's_uuid' => (string) $row['s_uuid'],
            's_email' => (string) $row['s_email'],
            's_fname' => (string) $row['s_fname'],
            's_lname' => (string) $row['s_lname'],
        ], $this->db->fetchAllAssociative(
            'SELECT s_id, s_uuid, s_email, COALESCE(s_fname, \'\') AS s_fname, COALESCE(s_lname, \'\') AS s_lname FROM subscribers
             WHERE s_email ILIKE :like OR s_fname ILIKE :like OR s_lname ILIKE :like'
            . (preg_match(self::UUID_PATTERN, $uuid) ? ' OR s_uuid = CAST(:uuid AS UUID)' : '') . '
             ORDER BY s_email
             LIMIT ' . max(1, $limit),
            ['like' => $like] + (preg_match(self::UUID_PATTERN, $uuid) ? ['uuid' => $uuid] : [])
        ));
    }

    public function exists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM subscribers WHERE s_id = ?', [$id]) !== false;
    }

    public function recordLogin(int $id, string $at, string $ip): void
    {
        $this->db->executeStatement(
            'UPDATE subscribers SET s_last_login_at = ?, s_last_login_ip = ? WHERE s_id = ?',
            [$at, mb_substr($ip, 0, 45), $id]
        );
    }

    /** @return Recipient|null */
    private function recipient(string $condition, string $value): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT s_id, s_uuid, s_email, s_fname, s_lname, s_emailsleft, s_priority, s_last_interacted, s_delivery_state FROM subscribers WHERE ' . $condition,
            [$value]
        );
        if ($row === false) {
            return null;
        }
        return [
            's_id' => (int) $row['s_id'],
            's_uuid' => (string) $row['s_uuid'],
            's_email' => (string) $row['s_email'],
            's_fname' => trim((string) $row['s_fname']),
            's_lname' => trim((string) $row['s_lname']),
            's_emailsleft' => (int) $row['s_emailsleft'],
            's_priority' => (int) $row['s_priority'],
            's_last_interacted' => $row['s_last_interacted'] === null ? null : (string) $row['s_last_interacted'],
            's_delivery_state' => (string) $row['s_delivery_state'],
        ];
    }

    /**
     * Record a catto-mail hard bounce or complaint: the subscriber leaves
     * campaign selection (a complaint is never downgraded to a bounce).
     * Also counts the bounce (v5 s_bounces) for a hard bounce.
     */
    public function recordDeliveryProblem(int $id, string $state, string $at): void
    {
        $this->db->executeStatement(
            "UPDATE subscribers SET
                s_delivery_state = CASE WHEN s_delivery_state = 'complained' THEN s_delivery_state ELSE :state END,
                s_delivery_state_at = :at,
                s_bounces = s_bounces + CASE WHEN :state = 'hard_bounced' THEN 1 ELSE 0 END
             WHERE s_id = :id",
            ['state' => $state, 'at' => $at, 'id' => $id]
        );
    }

    /** An administrator returns the subscriber to campaign selection (e.g. the address was fixed by its owner). */
    /** @return array{state: string, at: ?string} the catto-mail delivery block (state ok: none) */
    public function deliveryState(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT s_delivery_state, s_delivery_state_at FROM subscribers WHERE s_id = ?', [$id]);
        return ['state' => (string) ($row['s_delivery_state'] ?? 'ok'), 'at' => isset($row['s_delivery_state_at']) ? (string) $row['s_delivery_state_at'] : null];
    }

    /**
     * Lift the sending block only: memberships and consent are not touched
     * (a complaint's unsubscriptions stay).
     *
     * @return string the state that was cleared ('ok' when there was none)
     */
    public function clearDeliveryProblem(int $id): string
    {
        $previous = $this->db->fetchOne(
            "UPDATE subscribers s SET s_delivery_state = 'ok', s_delivery_state_at = NULL
             FROM (SELECT s_id, s_delivery_state AS before FROM subscribers WHERE s_id = :id FOR UPDATE) p
             WHERE s.s_id = p.s_id RETURNING p.before",
            ['id' => $id]
        );
        return $previous === false ? 'ok' : (string) $previous;
    }

    /**
     * @param array<string, mixed>|false $row
     * @return Identity|null
     */
    private function identity(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        return [
            's_id' => (int) $row['s_id'],
            's_uuid' => (string) $row['s_uuid'],
            's_email' => (string) $row['s_email'],
            's_fname' => trim((string) $row['s_fname']),
            's_lname' => trim((string) $row['s_lname']),
        ];
    }
}
