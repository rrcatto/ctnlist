<?php

declare(strict_types=1);

namespace App\Legacy;

use App\Subscriber\EmailNormaliser;
use Base;

class SubscribersM extends \DB\SQL\Mapper
{
    private Base $fat;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        parent::__construct($fat->get('dbPDO'), 'subscribers');
    }

    public function read(string $uuid): bool
    {
        $uuid = strtolower(trim($uuid));
        if (!UuidV7::isValid($uuid)) {
            $this->reset();
            return false;
        }

        $this->load(['s_uuid = :uuid', ':uuid' => $uuid]);
        if ($this->valid()) {
            $this->fixEmail();
        }
        return $this->valid();
    }

    public function loadByEmail(string $email): bool
    {
        $email = $this->normaliseCandidateEmail($email);
        $this->load(['LOWER(s_email) = :email', ':email' => $email]);
        if ($this->valid()) {
            $this->fixEmail();
        }
        return $this->valid();
    }

    public function isSubscribed(string $email): bool
    {
        return $this->count(['LOWER(s_email) = :email', ':email' => self::normaliseEmail($email)]) > 0;
    }

    public function getEmail(string $token): string
    {
        return $this->read($token) ? (string) $this->s_email : '';
    }

    public function createIdentity(string $email): bool
    {
        $email = $this->normaliseCandidateEmail($email);
        if (!self::validEmail($email)) {
            return false;
        }

        if ($this->loadByEmail($email)) {
            return true;
        }

        $this->reset();
        $this->s_uuid = UuidV7::generate();
        $this->s_email = $email;
        $this->s_created_at = date('Y-m-d H:i:s');
        $this->save();
        return true;
    }

    public function bumpPriority(string $token, int $amount = 1): bool
    {
        if (!$this->read($token)) {
            return false;
        }

        $this->s_priority = (int) $this->s_priority + $amount;
        $this->s_bounces = 0;
        $this->s_emailsleft = (int) $this->fat->get('SubscriptionConfirmAmount');
        $this->s_last_interacted = date('Y-m-d H:i:s');
        $this->save();
        return true;
    }

    public function resetPriority(string $token): bool
    {
        if (!$this->read($token)) {
            return false;
        }

        $this->s_bounces = 0;
        $this->s_emailsleft = 0;
        $this->s_priority = 0;
        $this->s_last_interacted = null;
        $this->save();
        return true;
    }

    public function setPriority(string $token, int $priority): bool
    {
        if (!$this->read($token)) {
            return false;
        }

        $this->s_bounces = 0;
        $this->s_emailsleft = (int) $this->fat->get('SubscriptionConfirmAmount');
        $this->s_priority = $priority;
        $this->s_last_interacted = date('Y-m-d H:i:s');
        $this->save();
        return true;
    }

    public function numsubscribers(): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT s.s_id) AS total
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             WHERE ls.ls_confirmed = TRUE
               AND ls.ls_unsubscribed = FALSE
               AND l.l_active = TRUE'
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function activeReaders(): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT s.s_id) AS total
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             WHERE s.s_last_interacted IS NOT NULL
               AND ls.ls_confirmed = TRUE
               AND ls.ls_unsubscribed = FALSE
               AND l.l_active = TRUE'
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function confirmed(): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT ls_s_id) AS total FROM list_subscribers WHERE ls_confirmed = TRUE AND ls_unsubscribed = FALSE'
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    public function unsubscribed(): int
    {
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT ls_s_id) AS total FROM list_subscribers WHERE ls_unsubscribed = TRUE'
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * Count subscriber rows for the administrator report. Complex aggregate
     * membership reporting is isolated in the model rather than controllers.
     */
    public function reportCount(string $searchEmail = '', bool $activeOnly = false, bool $includeUnsubscribed = false, int $listId = 0): int
    {
        [$where, $params] = $this->reportFilter($searchEmail, $activeOnly, $includeUnsubscribed, $listId);
        $rows = $this->db->exec(
            'SELECT COUNT(DISTINCT s.s_id) AS total FROM subscribers s ' . $where,
            $params
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function reportPage(string $searchEmail, int $offset, int $limit, bool $activeOnly = false, bool $includeUnsubscribed = false, int $listId = 0): array
    {
        [$where, $params] = $this->reportFilter($searchEmail, $activeOnly, $includeUnsubscribed, $listId);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return $this->db->exec(
            "SELECT s.s_id, s.s_uuid, s.s_email, s.s_fname, s.s_lname,
                    s.s_priority, s.s_last_interacted, s.s_bounces, s.s_emailsleft,
                    STRING_AGG(
                        l.l_name || ':' ||
                        CASE
                            WHEN ls.ls_confirmed AND NOT ls.ls_unsubscribed THEN 'confirmed'
                            WHEN ls.ls_unsubscribed THEN 'unsubscribed'
                            ELSE 'pending'
                        END,
                        ', ' ORDER BY l.l_name
                    ) AS memberships
             FROM subscribers s
             LEFT JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             LEFT JOIN lists l ON l.l_id = ls.ls_l_id
             {$where}
             GROUP BY s.s_id
             ORDER BY (s.s_last_interacted IS NULL) ASC,
                      s.s_last_interacted DESC, s.s_priority DESC, s.s_email ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @return list<string> */
    public function exportEmails(bool $active, int $offset = 0, int $limit = 10000000, int $listId = 0): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(10000000, $limit));
        $params = [];
        $listClause = '';
        if ($listId > 0) {
            $listClause = ' AND ls.ls_l_id = :list_id';
            $params[':list_id'] = $listId;
        }

        if ($active) {
            $sql = "SELECT DISTINCT s.s_email
                    FROM subscribers s
                    JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
                    JOIN lists l ON l.l_id = ls.ls_l_id
                    WHERE ls.ls_confirmed = TRUE
                      AND ls.ls_unsubscribed = FALSE
                      AND l.l_active = TRUE {$listClause}
                    ORDER BY s.s_email
                    LIMIT {$limit} OFFSET {$offset}";
        } else {
            // Equivalent of the old export-remove list: subscriber identities
            // that have an unsubscribed membership and no active confirmed one.
            $sql = "SELECT DISTINCT s.s_email
                    FROM subscribers s
                    JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
                    WHERE ls.ls_unsubscribed = TRUE {$listClause}
                      AND NOT EXISTS (
                          SELECT 1 FROM list_subscribers active_ls
                          JOIN lists active_l ON active_l.l_id = active_ls.ls_l_id
                          WHERE active_ls.ls_s_id = s.s_id
                            AND active_ls.ls_confirmed = TRUE
                            AND active_ls.ls_unsubscribed = FALSE
                            AND active_l.l_active = TRUE
                      )
                    ORDER BY s.s_email
                    LIMIT {$limit} OFFSET {$offset}";
        }
        return array_values(array_map(
            static fn(array $row): string => (string) $row['s_email'],
            $this->db->exec($sql, $params)
        ));
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function reportFilter(string $searchEmail, bool $activeOnly, bool $includeUnsubscribed, int $listId): array
    {
        $clauses = [];
        $params = [];
        $searchEmail = trim($searchEmail);
        if ($searchEmail !== '') {
            $clauses[] = 'LOWER(s.s_email) LIKE LOWER(:email)';
            $params[':email'] = '%' . $searchEmail . '%';
        }
        if ($activeOnly) {
            $clauses[] = 's.s_last_interacted IS NOT NULL';
        }
        if (!$includeUnsubscribed) {
            $clauses[] = 'EXISTS (
                SELECT 1 FROM list_subscribers active_ls
                JOIN lists active_l ON active_l.l_id = active_ls.ls_l_id
                WHERE active_ls.ls_s_id = s.s_id
                  AND active_ls.ls_confirmed = TRUE
                  AND active_ls.ls_unsubscribed = FALSE
                  AND active_l.l_active = TRUE
            )';
        }
        if ($listId > 0) {
            $clauses[] = 'EXISTS (SELECT 1 FROM list_subscribers filter_ls WHERE filter_ls.ls_s_id = s.s_id AND filter_ls.ls_l_id = :list_id)';
            $params[':list_id'] = $listId;
        }
        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function maskEmail(string $email): string
    {
        $email = self::normaliseEmail($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '***';
        }
        $visible = mb_substr($local, 0, 1);
        return $visible . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }



    /**
     * Apply the established v5 email cleanup rules before validation. The old
     * implementation also deleted subscriber rows during a read when an
     * address was suppressed. v5.0.1 deliberately keeps identity separate from
     * per-list consent, so cleanup remains here while shared suppression is
     * enforced by App\Subscriber\SubscriptionService and the queue workflow.
     */
    public function normaliseCandidateEmail(string $email): string
    {
        $email = self::normaliseEmail($email);
        $email = (string) $this->fixCommonErrors($email);
        $email = (string) $this->addressChanges($email);
        $user = (string) $this->fixUser($this->getEmailUser($email));
        $domain = (string) $this->fixDomain($this->getEmailDomain($email));
        return self::normaliseEmail($user . '@' . $domain);
    }

    /**
     * Correct an already-loaded stored address without deleting the canonical
     * subscriber identity. Returns false only when the cleaned address is not
     * syntactically usable or would collide with another subscriber.
     */
    public function fixEmail(): bool
    {
        if ($this->dry()) {
            return true;
        }
        $current = self::normaliseEmail((string) $this->s_email);
        $corrected = $this->normaliseCandidateEmail($current);
        if (!self::validEmail($corrected)) {
            return false;
        }
        if ($corrected === $current) {
            return true;
        }

        $duplicates = $this->count([
            'LOWER(s_email) = :email AND s_id <> :id',
            ':email' => $corrected,
            ':id' => (int) $this->s_id,
        ]);
        if ($duplicates > 0) {
            error_log('ctnlist subscriber email correction would create duplicate: ' . $corrected);
            return false;
        }

        $this->s_email = $corrected;
        $this->save();
        return true;
    }

    public function fixUser($user) {
        return EmailNormaliser::fixUser((string) $user);
    }

    public function fixDomain($domain) {
        return EmailNormaliser::fixDomain((string) $domain);
    }

    public function fixCommonErrors($email) {
        return EmailNormaliser::fixCommonErrors((string) $email);
    }

    public function addressChanges($email) {
        return EmailNormaliser::addressChanges((string) $email);
    }

    public function getEmailUser(string $email): string
    {
        return explode('@', self::normaliseEmail($email), 2)[0];
    }

    public function getEmailDomain(string $email): string
    {
        return explode('@', self::normaliseEmail($email), 2)[1] ?? '';
    }
}