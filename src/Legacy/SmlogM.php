<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;
use InvalidArgumentException;

/**
 * Subscriber/message activity model.
 *
 * The existence of a row for (subscriber UUID, message MUID) is part of the
 * once-only campaign delivery guarantee. Do not treat this table as optional
 * analytics data.
 */
class SmlogM extends \DB\SQL\Mapper
{
    private Base $fat;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        parent::__construct($fat->get('dbPDO'), 'smlog');
    }

    public function deletemsg(string $muid): void
    {
        $this->erase(['sml_muid = :muid', ':muid' => $muid]);
    }

    public function hasMessageRecord(string $subscriberToken, string $muid): bool
    {
        $this->load([
            'sml_s_uuid = :token AND sml_muid = :muid',
            ':token' => strtolower(trim($subscriberToken)),
            ':muid' => trim($muid),
        ]);
        return $this->valid();
    }

    /**
     * Return the list context recorded for this subscriber/message delivery.
     * Deliberate resends and forwards use it so campaign headers and audit
     * records retain the original list rather than guessing one.
     */
    public function listShortcode(string $subscriberToken, string $muid): string
    {
        $this->load([
            'sml_s_uuid = :token AND sml_muid = :muid',
            ':token' => strtolower(trim($subscriberToken)),
            ':muid' => trim($muid),
        ]);
        return $this->valid() ? strtoupper(trim((string) $this->sml_list_shortcode)) : '';
    }

    /**
     * Load or create the once-only activity row for a real subscriber/message.
     */
    public function read(string $subscriberToken, string $muid, string $listShortcode = ''): bool
    {
        $subscriberToken = strtolower(trim($subscriberToken));
        $muid = trim($muid);
        if ($subscriberToken === '' || $muid === '') {
            $this->reset();
            return false;
        }

        $subscriber = new SubscribersM($this->fat);
        $message = new MessagesM($this->fat);
        if (!$subscriber->read($subscriberToken) || !$message->read($muid)) {
            $this->reset();
            return false;
        }

        $this->load([
            'sml_s_uuid = :token AND sml_muid = :muid',
            ':token' => $subscriberToken,
            ':muid' => $muid,
        ]);

        if ($this->dry()) {
            $this->reset();
            $this->sml_s_uuid = $subscriberToken;
            $this->sml_muid = $muid;
            $this->sml_email = (string) $subscriber->s_email;
            $this->sml_list_shortcode = strtoupper(trim($listShortcode));
            $this->save();
        } elseif (trim($listShortcode) !== '') {
            $this->sml_list_shortcode = strtoupper(trim($listShortcode));
            $this->save();
        }

        return $this->valid();
    }

    /** @return array{ucount:int,total:int} */
    public function aggregateCounts(string $muid, string $field): array
    {
        $allowed = [
            'sml_reads', 'sml_updates', 'sml_confirms', 'sml_forwards',
            'sml_bookings', 'sml_subscribe', 'sml_unsubscribe',
        ];
        if (!in_array($field, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported smlog aggregate field.');
        }

        // Aggregate functions are not naturally represented by a single-table
        // Mapper object. Keep this parameterised query isolated in the model.
        $rows = $this->db->exec(
            "SELECT COUNT(*) AS ucount, COALESCE(SUM({$field}), 0) AS total
             FROM smlog
             WHERE sml_muid = :muid AND {$field} > 0",
            [':muid' => $muid]
        );
        return [
            'ucount' => (int) ($rows[0]['ucount'] ?? 0),
            'total' => (int) ($rows[0]['total'] ?? 0),
        ];
    }
    /** @return list<array<string,mixed>> */
    public function messageHistory(string $subscriberToken): array
    {
        // A joined report is clearer and safer here than duplicating Mapper
        // iteration in UsersController. The SQL remains isolated in the model.
        return $this->db->exec(
            'SELECT m.m_uniqid, m.m_subject, sml.sml_date_sent,
                    sml.sml_reads, sml.sml_likes, sml.sml_dislikes
             FROM smlog sml
             JOIN messages m ON m.m_uniqid = sml.sml_muid
             WHERE sml.sml_s_uuid = :token
               AND sml.sml_date_sent IS NOT NULL
             ORDER BY sml.sml_date_sent DESC',
            [':token' => strtolower(trim($subscriberToken))]
        );
    }

}
