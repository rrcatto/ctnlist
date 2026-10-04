<?php

declare(strict_types=1);

namespace App\Log;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Send Log (`sendlog`): one row for every successful email handoff,
 * campaign or transactional (invariant).
 */
final class SendLog
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string $type MESSAGE, PROOF, RESEND, FORWARD-MESSAGE, FORWARD-NOTIFICATION, SUBSCRIBE,
     *                     CONFIRM, UNSUBSCRIBE, UPDATE-PROFILE, UPDATE-USER, MAGIC-LINK, CONTACT, …
     */
    public function record(string $muid, string $type, string $email, string $listShortcode, string $subject, ?string $subscriberUuid = null): void
    {
        $subscriberUuid = strtolower(trim((string) $subscriberUuid));
        $this->db->insert('sendlog', [
            'sl_muid' => $muid,
            'sl_datesent' => $this->clock->now()->format('Y-m-d H:i:s'),
            'sl_type' => $type,
            'sl_email' => $email,
            'sl_list_shortcode' => strtoupper(trim($listShortcode)),
            'sl_s_uuid' => $subscriberUuid !== '' ? $subscriberUuid : null,
            'sl_subject' => $subject,
        ]);
    }
}
