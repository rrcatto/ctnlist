<?php

declare(strict_types=1);

/**
 * Delivery queue controller.
 *
 * Preserves the v5.0 queue ordering, SMTP failover, batching and retry model
 * while using UUIDv7 subscriber identities and per-list eligibility.
 */
class QueueController extends Controller
{
    protected Base $fat;
    protected \DB\SQL $dbPDO;
    public QueueM $queue;

    public function __construct(
        Base $fat,
        public SubscribersController $subscriber,
        public MessagesController $message,
        public TemplatesController $template,
        public mailer $mailer,
        public OptionsController $options
    ) {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->queue = new QueueM($fat);
    }

    /** Legacy name retained; this clears rows and does not drop the table. */
    public function DropTable(): int
    {
        return (int) $this->queue->erase();
    }

    public function QueueCount(): int
    {
        return (int) $this->queue->queuecount();
    }

    public function CreateQueueHTMLList(int $pageNo, int $numRows): string
    {
        if (!Controller::allowed($this->fat, 'queue.process')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $total = (int) $this->queue->count();
        if ($total === 0) {
            return '<p class="{{@pclass}}">There is nothing in the queue.</p>';
        }

        $pageNo = max(1, $pageNo);
        $numRows = max(1, min(200, $numRows));
        $page = $this->queue->paginate(
            $pageNo - 1,
            $numRows,
            null,
            ['order' => 'q_mpriority DESC, (q_last_interacted IS NULL) ASC, q_last_interacted DESC, q_spriority DESC, q_id ASC']
        );

        $html = '<div class="d-flex gap-2 mb-3">'
            . '<a class="btn btn-primary" href="{{@BaseURL}}processqueue">Process Queue</a>'
            . '<form method="post" action="{{@BaseURL}}queue/clear" onsubmit="return confirm(\'Clear the entire queue?\')">'
            . Csrf::field($this->fat) . '<button class="btn btn-outline-danger" type="submit">Clear Queue</button></form>'
            . '</div>';
        $html .= '<div class="table-responsive"><table class="table table-striped table-hover table-bordered">'
            . '<thead><tr><th>Message</th><th>Subscriber</th><th>List</th><th>Added</th>'
            . '<th>Last interaction</th><th>MP:SP</th><th></th></tr></thead><tbody>';

        foreach ($page['subset'] as $row) {
            $muid = (string) $row['q_muid'];
            $token = (string) $row['q_s_uuid'];
            $subject = stripslashes((string) $row['q_subject']);
            $shortSubject = mb_substr($subject, 0, 80) . (mb_strlen($subject) > 80 ? '…' : '');
            $messageLink = '<a href="{{@BaseURL}}message/' . rawurlencode($muid) . '" title="'
                . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($shortSubject) . '</a>';
            $subscriberLink = '<a href="{{@BaseURL}}subscribe/' . rawurlencode($token) . '">'
                . htmlspecialchars((string) $row['q_email']) . '</a>';
            $last = trim((string) $row['q_last_interacted']) !== ''
                ? htmlspecialchars((string) $row['q_last_interacted'])
                : '-';
            $delete = '<form method="post" action="{{@BaseURL}}queue/delete">' . Csrf::field($this->fat)
                . '<input type="hidden" name="queue_id" value="' . (int) $row['q_id'] . '">'
                . '<button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>';

            $html .= '<tr><td>' . $messageLink . '</td><td>' . $subscriberLink . '</td>'
                . '<td>' . htmlspecialchars((string) $row['q_list_shortcode']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['q_date_added']) . '</td>'
                . '<td>' . $last . '</td>'
                . '<td>' . (int) $row['q_mpriority'] . ':' . (int) $row['q_spriority'] . '</td>'
                . '<td>' . $delete . '</td></tr>';
        }

        $html .= '</tbody></table></div>';
        return $html . (new htmlhelper($this->fat))->paginate($page, 'queue');
    }

    /** Return true only when a new queue row was inserted. */
    public function AddToQueue(
        string $muid,
        string $subscriberToken,
        string $subject,
        string $email,
        string $listShortcode,
        mixed $lastInteracted,
        int $messagePriority,
        int $subscriberPriority
    ): bool {
        $existing = new QueueM($this->fat);
        $existing->load([
            'q_muid = :muid AND q_s_uuid = :token',
            ':muid' => $muid,
            ':token' => $subscriberToken,
        ]);
        if ($existing->valid()) {
            return false;
        }

        try {
            $row = new QueueM($this->fat);
            $row->q_muid = $muid;
            $row->q_subject = $subject;
            $row->q_s_uuid = $subscriberToken;
            $row->q_email = $email;
            $row->q_list_shortcode = strtoupper(trim($listShortcode));
            $row->q_last_interacted = $lastInteracted ?: null;
            $row->q_mpriority = $messagePriority;
            $row->q_spriority = $subscriberPriority;
            $row->save();
            return true;
        } catch (\Throwable $e) {
            error_log('ctnlist queue insert failed: ' . $e->getMessage());
            return false;
        }
    }

    public function DeleteQueueItem(int $queueId): bool
    {
        if ($queueId < 1) {
            return false;
        }
        $row = new QueueM($this->fat);
        $row->load(['q_id = :id', ':id' => $queueId]);
        if (!$row->valid()) {
            return false;
        }
        $row->erase();
        return true;
    }

    public function ClearQueue(): int
    {
        return (int) (new QueueM($this->fat))->erase();
    }

    /**
     * Process queued campaign mail with the v5.0 SMTP retry/failover contract.
     */
    public function ProcessQueue(string $muid = '', int $totalToSend = 250000): int
    {
        set_time_limit(86400);
        if ($this->options->GetOption('CurrentlySending') === 'Y') {
            return 0;
        }

        $this->options->SetOption('SendQueue', 'Y');
        $this->options->SetOption('CurrentlySending', 'Y');
        $sentCount = 0;
        $servers = $this->smtpServers();
        if ($servers === []) {
            $this->options->SetOption('CurrentlySending', 'N');
            return 0;
        }

        $serverIndex = 0;
        $batchSize = 600;
        $opened = $this->openAvailableServer($servers, $serverIndex, $batchSize);
        if (!$opened) {
            $this->options->SetOption('CurrentlySending', 'N');
            return 0;
        }

        try {
            do {
                $filter = $muid === '' ? null : ['q_muid = :muid', ':muid' => $muid];
                $this->queue->load($filter, [
                    'order' => 'q_mpriority DESC, (q_last_interacted IS NULL) ASC, q_last_interacted DESC, q_spriority DESC, q_id ASC',
                    'limit' => $batchSize,
                ]);
                if ($this->queue->dry()) {
                    break;
                }

                while ($this->queue->valid() && $sentCount < $totalToSend) {
                    if ($this->options->GetOption('SendQueue') === 'N') {
                        break 2;
                    }

                    $queueId = (int) $this->queue->q_id;
                    $queueMuid = (string) $this->queue->q_muid;
                    $subscriberToken = (string) $this->queue->q_s_uuid;

                    if (!$this->subscriber->RetrieveSubscriber($subscriberToken)
                        || !$this->message->RetrieveMessage($queueMuid)) {
                        $this->DeleteQueueItem($queueId);
                        $this->queue->skip();
                        continue;
                    }

                    // The shared suppression database can change after a row
                    // was queued. Recheck it immediately before delivery and
                    // synchronise the local multi-list memberships with the
                    // old v5 subscriber-level unsubscribe meaning.
                    if ($this->subscriber->IsGloballySuppressed(
                        (string) $this->subscriber->subscriber->s_email
                    )) {
                        $this->subscriber->UnsubscribeAllLists(
                            (int) $this->subscriber->subscriber->s_id,
                            'Shared global suppression database'
                        );
                        $this->DeleteQueueItem($queueId);
                        $this->queue->skip();
                        continue;
                    }

                    $eligibleListShortcode = $this->eligibleListShortcode(
                        (int) $this->subscriber->subscriber->s_id,
                        (int) $this->message->message->m_id,
                        (string) $this->queue->q_list_shortcode
                    );
                    if ($eligibleListShortcode === '') {
                        $this->DeleteQueueItem($queueId);
                        $this->queue->skip();
                        continue;
                    }

                    // Preserve v5.0 semantics exactly: m_max_send is an actual
                    // maximum, including when the administrator set it to zero.
                    $sentForMessage = (int) $this->message->message->m_sent;
                    $maximumForMessage = (int) $this->message->message->m_max_send;
                    if ($sentForMessage >= $maximumForMessage) {
                        break 2;
                    }

                    $this->fat->set('CurrentQueueListShortcode', $eligibleListShortcode);
                    $this->template->MergeTemplate();
                    $sent = $this->mailer->SendMessage('MESSAGE');

                    if ($sent < 1) {
                        // Restore v5.0 behaviour: reconnect/fail over and retry
                        // the same queue item once before stopping the process.
                        $this->mailer->CloseSMTP();
                        sleep(max(0, (int) ($servers[$serverIndex]['delay'] ?? 10)));
                        $serverIndex = 0;
                        if (!$this->openAvailableServer($servers, $serverIndex, $batchSize)) {
                            return $sentCount;
                        }
                        $sent = $this->mailer->SendMessage('MESSAGE');
                    }

                    if ($sent >= 1) {
                        $this->message->message->m_sent = (int) $this->message->message->m_sent + 1;
                        $this->message->message->save();
                        $this->DeleteQueueItem($queueId);
                        $sentCount++;
                    } else {
                        return $sentCount;
                    }
                    $this->queue->skip();
                }

                sleep(max(0, (int) ($servers[$serverIndex]['delay'] ?? 10)));
            } while ($sentCount < $totalToSend);
        } finally {
            $this->mailer->CloseSMTP();
            $this->options->SetOption('CurrentlySending', 'N');
        }

        return $sentCount;
    }

    /**
     * Return the currently valid list context for a queued subscriber/message.
     * Prefer the list recorded when the row was queued, but if membership has
     * changed and another selected list is still valid, use that list instead.
     */
    private function eligibleListShortcode(int $subscriberId, int $messageId, string $preferred): string
    {
        $preferred = strtoupper(trim($preferred));
        $params = [':mid' => $messageId, ':sid' => $subscriberId];
        $preferenceOrder = 'l.l_system DESC, l.l_shortcode ASC';
        if ($preferred !== '') {
            $params[':preferred'] = $preferred;
            $preferenceOrder = 'CASE WHEN l.l_shortcode = :preferred THEN 0 ELSE 1 END, '
                . $preferenceOrder;
        }

        // This is the central multi-list eligibility join and is intentionally
        // isolated in the queue service rather than repeated in controllers.
        $rows = $this->dbPDO->exec(
            'SELECT l.l_shortcode
             FROM message_lists ml
             JOIN lists l ON l.l_id = ml.ml_l_id
             JOIN list_subscribers ls ON ls.ls_l_id = ml.ml_l_id
             WHERE ml.ml_m_id = :mid
               AND ls.ls_s_id = :sid
               AND ls.ls_confirmed = TRUE
               AND ls.ls_unsubscribed = FALSE
               AND l.l_active = TRUE
             ORDER BY ' . $preferenceOrder . '
             LIMIT 1',
            $params
        );
        return strtoupper((string) ($rows[0]['l_shortcode'] ?? ''));
    }

    /** @return list<array<string,mixed>> */
    private function smtpServers(): array
    {
        $servers = $this->fat->get('smtp_servers');
        if (is_array($servers) && $servers !== []) {
            return array_values(array_filter(
                $servers,
                static fn(mixed $server): bool => is_array($server) && (int) ($server['active'] ?? 0) === 1
            ));
        }

        // Single MAILER_DSN remains a valid configuration and is represented as
        // one server so the legacy batching/retry loop is retained.
        $dsn = $this->envString('MAILER_DSN');
        if ($dsn === '') {
            return [];
        }
        return [[
            'active' => 1,
            'dsn' => $dsn,
            'batchsize' => max(1, $this->envInt('MAIL_BATCH_SIZE', 600)),
            'delay' => max(0, $this->envInt('MAIL_BATCH_DELAY', 0)),
            'sendrate' => max(0, $this->envInt('MAIL_RATE_PER_MINUTE', 13)),
        ]];
    }

    /**
     * @param list<array<string,mixed>> $servers
     */
    private function openAvailableServer(array $servers, int &$serverIndex, int &$batchSize): bool
    {
        for ($i = $serverIndex; $i < count($servers); $i++) {
            $server = $servers[$i];
            $batchSize = max(1, (int) ($server['batchsize'] ?? 600));
            $transport = isset($server['dsn']) ? (string) $server['dsn'] : $server;
            if ($this->mailer->OpenSMTP($transport)) {
                $serverIndex = $i;
                return true;
            }
        }
        return false;
    }

    private function envString(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private function envInt(string $name, int $default): int
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }
}
