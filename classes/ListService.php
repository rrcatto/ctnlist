<?php

declare(strict_types=1);

/**
 * Coordinates list, membership and message-list operations.
 *
 * ctnlist uses F3 Mapper models for normal runtime data access. The only raw
 * query in this class is the membership report, which is a read-only join
 * across lists and list_subscribers that is materially clearer as one query.
 */
final class ListService
{
    private ListsM $list;
    private ListSubscribersM $membership;
    private MessageListsM $messageList;

    public function __construct(private Base $fat, private \DB\SQL $db)
    {
        $this->list = new ListsM($fat);
        $this->membership = new ListSubscribersM($fat);
        $this->messageList = new MessageListsM($fat);
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $activeOnly = true): array
    {
        $filter = $activeOnly ? ['l_active = :active', ':active' => true] : null;
        return $this->rowsFromMapper($this->list, $filter, ['order' => 'l_system DESC, l_name ASC']);
    }

    public function allListId(): int
    {
        $list = $this->findByShortcode(ListsM::ALL_SHORTCODE);
        return (int) ($list['l_id'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $listId): ?array
    {
        if ($listId < 1) {
            return null;
        }
        $mapper = new ListsM($this->fat);
        if (!$mapper->readById($listId)) {
            return null;
        }
        return $mapper->cast();
    }

    /** @return array<string,mixed>|null */
    public function findByShortcode(string $shortcode): ?array
    {
        $mapper = new ListsM($this->fat);
        if (!$mapper->readByShortcode($shortcode)) {
            return null;
        }
        return $mapper->cast();
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $mapper = new ListsM($this->fat);
        $mapper->load(['LOWER(l_name) = LOWER(:name)', ':name' => $name]);
        return $mapper->valid() ? $mapper->cast() : null;
    }

    /**
     * Ensure the subscriber/list relationship exists without granting consent.
     */
    public function ensureMembership(int $subscriberId, int $listId): bool
    {
        if ($subscriberId < 1 || $listId < 1) {
            return false;
        }

        $membership = new ListSubscribersM($this->fat);
        if ($membership->readMembership($subscriberId, $listId)) {
            return false;
        }

        $membership->reset();
        $membership->ls_s_id = $subscriberId;
        $membership->ls_l_id = $listId;
        $membership->save();
        return true;
    }

    public function confirm(int $subscriberId, int $listId): bool
    {
        $this->ensureMembership($subscriberId, $listId);
        $membership = new ListSubscribersM($this->fat);
        if (!$membership->readMembership($subscriberId, $listId)) {
            return false;
        }

        $membership->ls_confirmed = true;
        $membership->ls_unsubscribed = false;
        $membership->ls_confirmed_at = date('Y-m-d H:i:s');
        $membership->ls_unsubscribed_at = null;
        $membership->ls_unsubscribe_reason = null;
        $membership->save();
        return true;
    }

    public function unsubscribe(int $subscriberId, int $listId, string $reason = ''): bool
    {
        $this->ensureMembership($subscriberId, $listId);
        $membership = new ListSubscribersM($this->fat);
        if (!$membership->readMembership($subscriberId, $listId)) {
            return false;
        }

        $membership->ls_confirmed = false;
        $membership->ls_unsubscribed = true;
        $membership->ls_unsubscribed_at = date('Y-m-d H:i:s');
        $membership->ls_unsubscribe_reason = mb_substr(trim($reason), 0, 255);
        $membership->save();
        return true;
    }

    public function unsubscribeAll(int $subscriberId, string $reason = ''): void
    {
        $membership = new ListSubscribersM($this->fat);
        $membership->load(['ls_s_id = :sid', ':sid' => $subscriberId]);
        while ($membership->valid()) {
            $membership->ls_confirmed = false;
            $membership->ls_unsubscribed = true;
            $membership->ls_unsubscribed_at = date('Y-m-d H:i:s');
            $membership->ls_unsubscribe_reason = mb_substr(trim($reason), 0, 255);
            $membership->save();
            $membership->skip();
        }
    }

    /** @return list<array<string,mixed>> */
    public function memberships(int $subscriberId): array
    {
        // A LEFT JOIN is needed so an administrator can see active lists for
        // which no membership record exists. F3 Mapper represents one table at
        // a time, so this read-only report remains an isolated parameterised SQL
        // query rather than scattering SQL through controllers.
        return $this->db->exec(
            'SELECT l.l_id, l.l_shortcode, l.l_name, l.l_description, l.l_system,
                    ls.ls_uuid, ls.ls_subscribed_at, ls.ls_confirmed_at,
                    ls.ls_unsubscribed_at, ls.ls_confirmed, ls.ls_unsubscribed,
                    ls.ls_unsubscribe_reason
             FROM lists l
             LEFT JOIN list_subscribers ls
               ON ls.ls_l_id = l.l_id AND ls.ls_s_id = :sid
             WHERE l.l_active = TRUE
             ORDER BY l.l_system DESC, l.l_name ASC',
            [':sid' => $subscriberId]
        );
    }

    /** @return list<int> */
    public function messageListIds(int $messageId): array
    {
        if ($messageId < 1) {
            return [];
        }
        $mapper = new MessageListsM($this->fat);
        $rows = $this->rowsFromMapper(
            $mapper,
            ['ml_m_id = :mid', ':mid' => $messageId],
            ['order' => 'ml_l_id ASC']
        );
        return array_values(array_map(static fn(array $row): int => (int) $row['ml_l_id'], $rows));
    }

    /** @return list<array<string,mixed>> */
    public function messageLists(int $messageId): array
    {
        if ($messageId < 1) {
            return [];
        }
        return $this->db->exec(
            'SELECT l.l_id, l.l_shortcode, l.l_name, l.l_active, l.l_system
             FROM message_lists ml
             JOIN lists l ON l.l_id = ml.ml_l_id
             WHERE ml.ml_m_id = :mid
             ORDER BY l.l_system DESC, l.l_name ASC',
            [':mid' => $messageId]
        );
    }

    /**
     * Save exactly the list choices made by the administrator.
     *
     * An empty array is valid: a message can remain an incomplete draft with no
     * audience. ALL is never added implicitly.
     *
     * @param list<int> $listIds
     */
    public function saveMessageLists(int $messageId, array $listIds): void
    {
        if ($messageId < 1) {
            return;
        }

        $listIds = array_values(array_unique(array_filter(
            array_map('intval', $listIds),
            static fn(int $id): bool => $id > 0
        )));

        $this->db->begin();
        try {
            $existing = new MessageListsM($this->fat);
            $existing->erase(['ml_m_id = :mid', ':mid' => $messageId]);

            foreach ($listIds as $listId) {
                if ($this->findById($listId) === null) {
                    continue;
                }
                $assignment = new MessageListsM($this->fat);
                $assignment->ml_m_id = $messageId;
                $assignment->ml_l_id = $listId;
                $assignment->save();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function create(string $shortcode, string $name, string $description = ''): int
    {
        $shortcode = strtoupper(trim($shortcode));
        $name = trim($name);
        if (!preg_match('/^[A-Z0-9]{3,6}$/', $shortcode)) {
            throw new InvalidArgumentException('List shortcode must contain 3 to 6 uppercase letters/numbers.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('List name is required.');
        }

        $list = new ListsM($this->fat);
        $list->l_shortcode = $shortcode;
        $list->l_name = $name;
        $list->l_description = trim($description);
        $list->save();
        return (int) ($list->l_id ?: $list->_id);
    }

    /**
     * Convert a mapper result to plain arrays so legacy controllers can retain
     * their established foreach style.
     *
     * @param array<int|string,mixed>|null $filter
     * @param array<string,mixed> $options
     * @return list<array<string,mixed>>
     */
    private function rowsFromMapper(\DB\SQL\Mapper $mapper, ?array $filter, array $options): array
    {
        $rows = [];
        $mapper->load($filter, $options);
        while ($mapper->valid()) {
            $rows[] = $mapper->cast();
            $mapper->skip();
        }
        return $rows;
    }
}
