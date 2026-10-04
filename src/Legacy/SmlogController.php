<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

/**
 * Controls subscriber/message activity and once-only delivery records.
 */
class SmlogController extends Controller
{
    public \DB\SQL $dbPDO;
    protected string $BaseURL;
    public SmlogM $smlog;
    public Base $fat;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->smlog = new SmlogM($fat);
    }

    /**
     * Paginated message activity report, restored from v5.0.
     */
    public function CreateMessageReadsHTMLList(
        string $muid = '',
        string $searchEmail = '',
        int $pageNo = 1,
        int $numRows = 25
    ): string {
        if ($muid === '') {
            return '<p>No such message.</p>';
        }
        if (!Controller::allowed($this->fat, 'logs.view')) {
            return '<p>Access denied.</p>';
        }

        $searchEmail = trim($searchEmail);
        $filter = $searchEmail === ''
            ? ['sml_muid = :muid AND sml_reads > 0', ':muid' => $muid]
            : [
                'sml_muid = :muid AND sml_reads > 0 AND LOWER(sml_email) LIKE LOWER(:email)',
                ':muid' => $muid,
                ':email' => '%' . $searchEmail . '%',
            ];

        $html = '<form name="messageviewsform" action="{{@BaseURL}}message-views/'
            . rawurlencode($muid) . '" method="get" class="row g-3 mb-4">'
            . '<div class="col-md-6"><label class="form-label">Email address</label>'
            . '<input class="form-control" type="search" name="e" value="'
            . htmlspecialchars($searchEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></div>'
            . '<div class="col-md-3 align-self-end"><button class="btn btn-primary" type="submit">Search message activity</button></div>'
            . '</form>';

        $totalMatches = (int) $this->smlog->count($filter);
        if ($totalMatches === 0) {
            return $html . '<p>No matching subscriber has read this message yet.</p>';
        }

        $numRows = max(1, min(200, $numRows));
        $lastPage = max(1, (int) ceil($totalMatches / $numRows));
        $pageNo = max(1, min($pageNo, $lastPage));
        $page = $this->smlog->paginate(
            $pageNo - 1,
            $numRows,
            $filter,
            ['order' => 'sml_last_read DESC, sml_id DESC']
        );

        $html .= '<div class="table-responsive"><table class="table table-striped table-hover table-bordered">'
            . '<thead><tr><th>Email</th><th>List</th><th>Reads</th><th>Last read</th>'
            . '<th>Likes</th><th>Last like</th><th>Dislikes</th><th>Last dislike</th>'
            . '<th>Forwards</th><th>Subscribe</th><th>Unsubscribe</th></tr></thead><tbody>';

        foreach ($page['subset'] as $row) {
            $html .= '<tr>'
                . '<td>' . htmlspecialchars((string) $row['sml_email']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['sml_list_shortcode']) . '</td>'
                . '<td>' . (int) $row['sml_reads'] . '</td>'
                . '<td>' . htmlspecialchars((string) $row['sml_last_read']) . '</td>'
                . '<td>' . (int) $row['sml_likes'] . '</td>'
                . '<td>' . htmlspecialchars((string) $row['sml_last_like']) . '</td>'
                . '<td>' . (int) $row['sml_dislikes'] . '</td>'
                . '<td>' . htmlspecialchars((string) $row['sml_last_dislike']) . '</td>'
                . '<td>' . (int) $row['sml_forwards'] . '</td>'
                . '<td>' . ((int) $row['sml_subscribe'] > 0 ? 'Yes' : '') . '</td>'
                . '<td>' . ((int) $row['sml_unsubscribe'] > 0 ? 'Yes' : '') . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';

        $queryString = $searchEmail === '' ? '' : '?e=' . rawurlencode($searchEmail);
        return $html . (new htmlhelper($this->fat))->paginate(
            $page,
            'message-views/' . rawurlencode($muid),
            $queryString
        );
    }

    // Compatibility name used by the first v5.0.1 routes.
    public function CreateSmlogHTMLList(string $muid = '', string $email = '', int $pageNo = 1, int $numRows = 25): string
    {
        return $this->CreateMessageReadsHTMLList($muid, $email, $pageNo, $numRows);
    }

    public function readLog(string $subscriberToken, string $muid, string $listShortcode = ''): bool
    {
        return $this->smlog->read($subscriberToken, $muid, $listShortcode);
    }

    public function hasMessageRecord(string $subscriberToken, string $muid): bool
    {
        $mapper = new SmlogM($this->fat);
        return $mapper->hasMessageRecord($subscriberToken, $muid);
    }

    public function listShortcode(string $subscriberToken, string $muid): string
    {
        $mapper = new SmlogM($this->fat);
        return $mapper->listShortcode($subscriberToken, $muid);
    }

    /** The model creates the record when a message is queued. */
    public function logMsgQueued(string $subscriberToken, string $muid, string $listShortcode = ''): bool
    {
        return $this->readLog($subscriberToken, $muid, $listShortcode);
    }

    public function delMsg(string $muid): void
    {
        $this->smlog->deletemsg($muid);
    }

    public function logMsgSent(string $subscriberToken, string $muid, string $listShortcode = ''): void
    {
        if ($this->readLog($subscriberToken, $muid, $listShortcode)) {
            $this->smlog->sml_date_sent = date('Y-m-d H:i:s');
            $this->smlog->save();
        }
    }

    private function logRead(string $date): void
    {
        $this->smlog->sml_reads = (int) $this->smlog->sml_reads + 1;
        $this->smlog->sml_last_read = $date;
    }

    public function logMsgRead(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgLike(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_likes = (int) $this->smlog->sml_likes + 1;
            $this->smlog->sml_last_like = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgDislike(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_dislikes = (int) $this->smlog->sml_dislikes + 1;
            $this->smlog->sml_last_dislike = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgUpdate(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_updates = (int) $this->smlog->sml_updates + 1;
            $this->smlog->sml_last_update = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgConfirm(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_confirms = (int) $this->smlog->sml_confirms + 1;
            $this->smlog->sml_confirmed_at = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgForward(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_forwards = (int) $this->smlog->sml_forwards + 1;
            $this->smlog->sml_last_forwarded = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgBooking(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_bookings = (int) $this->smlog->sml_bookings + 1;
            $this->smlog->sml_last_booking = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgSub(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_subscribe = 1;
            $this->smlog->sml_subscribed_at = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    public function logMsgUnsub(string $subscriberToken, string $muid): void
    {
        if ($this->readLog($subscriberToken, $muid)) {
            $date = date('Y-m-d H:i:s');
            $this->smlog->sml_unsubscribe = 1;
            $this->smlog->sml_unsubscribed_at = $date;
            $this->logRead($date);
            $this->smlog->save();
        }
    }

    /** @return array{ucount:int,total:int} */
    public function numberOfReads(string $muid): array { return $this->smlog->aggregateCounts($muid, 'sml_reads'); }
    /** @return array{ucount:int,total:int} */
    public function numberOfUpdates(string $muid): array { return $this->smlog->aggregateCounts($muid, 'sml_updates'); }
    /** @return array{ucount:int,total:int} */
    public function numberOfConfirms(string $muid): array { return $this->smlog->aggregateCounts($muid, 'sml_confirms'); }
    /** @return array{ucount:int,total:int} */
    public function numberOfForwards(string $muid): array { return $this->smlog->aggregateCounts($muid, 'sml_forwards'); }
    /** @return array{ucount:int,total:int} */
    public function numberOfBookings(string $muid): array { return $this->smlog->aggregateCounts($muid, 'sml_bookings'); }

    public function numberOfQueued(string $muid): int
    {
        return (int) $this->smlog->count(['sml_muid = :muid', ':muid' => $muid]);
    }

    public function numberOfSent(string $muid): int
    {
        return (int) $this->smlog->count(['sml_muid = :muid AND sml_date_sent IS NOT NULL', ':muid' => $muid]);
    }

    public function numberOfSubscribes(string $muid): int
    {
        return (int) $this->smlog->count(['sml_muid = :muid AND sml_subscribe > 0', ':muid' => $muid]);
    }

    public function numberOfUnsubscribes(string $muid): int
    {
        return (int) $this->smlog->count(['sml_muid = :muid AND sml_unsubscribe > 0', ':muid' => $muid]);
    }
}
