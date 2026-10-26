<?php

declare(strict_types=1);

namespace App\Log;

use App\Http\BotCheck;
use App\Repository\SiteLogRepository;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;

/**
 * The Flagged activity report: form submissions refused as automated
 * (BotCheck, recorded in the Site Log), the IP addresses behind bot-like
 * requests for the public form pages, and new subscriber records nobody has
 * confirmed, which bots typing other people's addresses into the forms leave
 * behind (each new address gets a record).
 */
final class FlaggedActivity
{
    /** How many refused submissions the page lists. */
    public const REFUSED_SHOWN = 100;

    /** How far back the page looks for records nobody has confirmed. */
    public const RECORD_DAYS = 30;

    /** How many of the newest requests for the form pages are examined for bot-like browsers. */
    private const FORM_REQUESTS_SCANNED = 5000;

    public function __construct(
        private readonly SiteLogRepository $siteLog,
        private readonly SubscriberRepository $subscribers,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return list<array<string, mixed>> newest first */
    public function refused(): array
    {
        return $this->siteLog->refused(self::REFUSED_SHOWN);
    }

    /**
     * Per IP address, the requests for the form pages that were refused or
     * came with a missing or outdated browser (BotCheck::browserReason()),
     * the most recent first. Requests logged before 6.1.3 count by their
     * browser alone.
     *
     * @return list<array{ip: string, host: string, requests: int, refused: int, first: string, last: string, agent: string, reason: string}>
     */
    public function addresses(): array
    {
        $byIp = [];
        foreach ($this->siteLog->formRequests(self::FORM_REQUESTS_SCANNED) as $row) {
            $reason = $row['stl_flag'] ?? BotCheck::browserReason($row['stl_agent']);
            if ($reason === null) {
                continue;
            }
            $ip = (string) $row['stl_ip'];
            // Rows come newest first: the first row of an address is its latest.
            $byIp[$ip] ??= ['ip' => $ip, 'host' => (string) $row['stl_host'], 'requests' => 0, 'refused' => 0,
                'first' => $row['stl_logged_at'], 'last' => $row['stl_logged_at'], 'agent' => (string) $row['stl_agent'], 'reason' => $reason];
            $byIp[$ip]['requests']++;
            $byIp[$ip]['refused'] += $row['stl_flag'] !== null ? 1 : 0;
            $byIp[$ip]['first'] = $row['stl_logged_at'];
        }
        return array_values($byIp);
    }

    /**
     * Records created in the last RECORD_DAYS days that never signed in and
     * were never confirmed on a list.
     *
     * @return list<array{s_uuid: string, s_email: string, s_created_at: string, emails_sent: int}>
     */
    public function unconfirmedRecords(): array
    {
        $since = $this->clock->now()->modify('-' . self::RECORD_DAYS . ' days')->format('Y-m-d H:i:s');
        return $this->subscribers->unconfirmedSince($since, 500);
    }
}
