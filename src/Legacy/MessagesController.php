<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

/**
 * Message administration, queue construction and subscriber interaction.
 *
 * v5.0 is the behavioural baseline. The v5.0.1 additions in this controller
 * are multiple target lists, UUIDv7 subscriber identities and ACL checks. A
 * message remains an administrator-controlled draft and may be saved with any
 * fields, including no selected lists.
 */
class MessagesController extends Controller
{
    protected Base $fat;
    protected \DB\SQL $dbPDO;
    protected string $BaseURL;
    protected string $FromAddress;
    protected string $AdminEmail;
    protected string $ListName;
    protected ListService $lists;

    public MessagesM $message;
    public SubscribersController $subscriber;
    public SmlogController $smlog;
    public OptionsController $options;
    public ?TemplatesController $template = null;
    public ?QueueController $queue = null;
    public ?ArchivesController $archive = null;
    public ?mailer $mailer = null;

    public function __construct(Base $fat, SubscribersController $subscriber, SmlogController $smlog, OptionsController $options)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->FromAddress = (string) $fat->get('FromAddress');
        $this->AdminEmail = (string) $fat->get('AdminEmail');
        $this->ListName = (string) $fat->get('ListName');
        $this->subscriber = $subscriber;
        $this->smlog = $smlog;
        $this->options = $options;
        $this->message = new MessagesM($fat);
        $this->lists = new ListService($fat, $this->dbPDO);
    }

    public function SetTemplate(TemplatesController $template): void { $this->template = $template; }
    public function SetQueue(QueueController $queue): void { $this->queue = $queue; }
    public function SetArchive(ArchivesController $archive): void { $this->archive = $archive; }
    public function SetMailer(mailer $mailer): void { $this->mailer = $mailer; }
    public function MessageCount(): int { return (int) $this->message->msgcount(); }
    public function RetrieveMessage(string $muid): bool { return $this->message->read($muid); }
    public function loadMessage(int $aid): bool { return $this->message->loadByAid($aid); }
    public function CreateMUID(): string { return $this->message->CreateMUID(); }

    /**
     * Send campaign content directly to one known subscriber. This deliberately
     * updates both sendlog and smlog, matching the established v5.0 contract.
     */
    public function SendToAddress(
        string $muid,
        string $email,
        string $sendType = 'RESEND',
        string $listShortcode = ''
    ): int {
        if ($this->mailer === null || $this->template === null || !$this->RetrieveMessage($muid)) {
            return 0;
        }
        if ($email === '' || !$this->subscriber->LoadSubscriber($email)) {
            return 0;
        }

        $listShortcode = strtoupper(trim($listShortcode));
        if ($listShortcode === '') {
            // Resends retain the original subscriber/message list context.
            // Proofs without a prior smlog record use the first explicitly
            // selected message list, or remain blank for an audience-less draft.
            $listShortcode = $this->smlog->listShortcode(
                (string) $this->subscriber->subscriber->s_uuid,
                $muid
            );
        }
        if ($listShortcode === '') {
            $listShortcode = $this->firstMessageListShortcode((int) $this->message->m_id);
        }

        $this->fat->set('CurrentQueueListShortcode', $listShortcode);
        $this->template->MergeTemplate();
        if (!$this->mailer->OpenSMTP()) {
            return 0;
        }
        try {
            return $this->mailer->SendMessage($sendType);
        } finally {
            $this->mailer->CloseSMTP();
        }
    }

    /**
     * Add one message's selected-list audience to the queue.
     *
     * The query is intentionally isolated here because it performs the central
     * multi-list union, duplicate elimination and smlog once-only test in one
     * atomic database read. Ordinary CRUD remains in Mapper models.
     */
    public function SendListToQueue(string $muid, int $sendLimit = 500000): string
    {
        if ($this->queue === null || $this->template === null || $this->archive === null) {
            return '<p class="{{@pclass}}">Message delivery services are not available.</p>';
        }
        set_time_limit(86400);
        $this->options->SetOption('ActiveMessage', $muid);

        if (!$this->RetrieveMessage($muid)) {
            return '<p class="{{@pclass}}">Message not found.</p>';
        }

        $messageId = (int) $this->message->m_id;
        $targetLists = $this->lists->messageLists($messageId);
        if ($targetLists === []) {
            return '<p class="{{@pclass}}">The message was not queued because it currently has no target lists. The saved message has not been altered.</p>';
        }

        $this->prepareMessageForQueue();
        $sendLimit = max(0, $sendLimit);
        if ($sendLimit === 0) {
            return '<p class="{{@pclass}}">Finished queueing: 0</p>';
        }

        $numQueued = 0;
        $batchSize = 5000;
        do {
            $remaining = $sendLimit - $numQueued;
            $limit = min($batchSize, $remaining);
            $rows = $this->eligibleSubscriberRows($messageId, $muid, $limit);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $subscriberToken = (string) $row['s_uuid'];
                if (!$this->subscriber->RetrieveSubscriber($subscriberToken)) {
                    continue;
                }
                if ($this->subscriber->IsGloballySuppressed((string) $row['s_email'])) {
                    // v5 represented this with the subscriber-level
                    // s_unsubscribe flag. The multi-list equivalent is to
                    // make every local membership ineligible.
                    $this->subscriber->UnsubscribeAllLists(
                        (int) $row['s_id'],
                        'Shared global suppression database'
                    );
                    continue;
                }

                $inserted = $this->queue->AddToQueue(
                    $muid,
                    $subscriberToken,
                    stripslashes((string) $this->message->m_subject),
                    (string) $row['s_email'],
                    (string) $row['l_shortcode'],
                    $row['s_last_interacted'],
                    (int) $this->message->m_priority,
                    (int) $row['s_priority']
                );
                if (!$inserted) {
                    continue;
                }

                // Preserve the v5 queue-time subscriber state changes.
                $this->subscriber->subscriber->s_bounces = 0;
                $this->subscriber->subscriber->s_priority = 0;
                $this->subscriber->subscriber->s_emailsleft = max(0, (int) $this->subscriber->subscriber->s_emailsleft - 1);
                $this->subscriber->subscriber->save();

                $this->message->m_queued = (int) $this->message->m_queued + 1;
                $this->message->save();
                $this->smlog->logMsgQueued($subscriberToken, $muid, (string) $row['l_shortcode']);
                $numQueued++;
                if ($numQueued >= $sendLimit) {
                    break;
                }
            }
        } while ($numQueued < $sendLimit);

        return '<p class="{{@pclass}}">Finished queueing: ' . $numQueued . '</p>';
    }

    public function CreateMessageHTMLDropDown(): string
    {
        $html = '<option value=""></option>';
        $mapper = new MessagesM($this->fat);
        $mapper->load(null, ['order' => 'm_id DESC']);
        while ($mapper->valid()) {
            $html .= '<option value="' . htmlspecialchars((string) $mapper->m_uniqid) . '">'
                . htmlspecialchars((string) $mapper->m_subject) . '</option>';
            $mapper->skip();
        }
        return $html;
    }

    public function CreateAdvancedQueueHTMLform(): string
    {
        if (!Controller::allowed($this->fat, 'messages.queue')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $csrf = Csrf::field($this->fat);
        $options = $this->CreateMessageHTMLDropDown();
        $html = '<form action="{{@BaseURL}}advanced-queue" method="post">' . $csrf
            . '<fieldset class="border rounded p-4"><legend>Send Multiple Messages</legend><div class="row g-3">';
        for ($i = 1; $i <= 4; $i++) {
            $html .= '<div class="col-12"><label class="form-label">Select Message ' . $i . '</label>'
                . '<select class="form-select" name="muid' . $i . '">' . $options . '</select></div>';
        }
        $html .= '<div class="col-12"><label class="form-label">Total number of emails to queue</label>'
            . '<input class="form-control" type="number" name="mvolume" value="1000000"></div>'
            . '<div class="col-12"><button class="btn btn-primary" type="submit">Queue Multiple Messages</button></div>'
            . '</div></fieldset></form>';
        return $html;
    }

    /**
     * Restore the v5 advanced-queue meaning: rotate selected messages so each
     * selected subscriber receives at most one of them and mvolume is the total
     * queue volume, not a separate limit per message.
     */
    public function AdvancedSendListToQueue(array $muids, int $mvolume): string
    {
        if ($this->queue === null || $this->archive === null) {
            return '<p class="{{@pclass}}">Queue services are unavailable.</p>';
        }
        set_time_limit(86400);
        $muids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): string => trim((string) $value), $muids),
            static fn(string $value): bool => $value !== ''
        )));
        if ($muids === []) {
            return '<p class="{{@pclass}}">Select at least one message before queueing.</p>';
        }
        $mvolume = max(0, $mvolume);
        if ($mvolume === 0) {
            return '<p class="{{@pclass}}">Finished queueing: 0</p>';
        }

        /** @var list<array{muid:string,mid:int,model:MessagesM}> $messages */
        $messages = [];
        foreach ($muids as $muid) {
            $model = new MessagesM($this->fat);
            if (!$model->read($muid)) {
                return '<p class="{{@pclass}}">Message ' . htmlspecialchars($muid) . ' does not exist.</p>';
            }
            if ($this->lists->messageListIds((int) $model->m_id) === []) {
                // Do not alter the draft or choose a list on the administrator's
                // behalf; simply omit a message that currently has no audience.
                continue;
            }
            if ($model->m_datesent === null || (string) $model->m_datesent === '') {
                $model->m_datesent = date('Y-m-d H:i:s');
                $model->save();
            }
            if ((int) $model->m_a_id === 0) {
                $model->m_a_id = $this->archive->CreateArchiveForMessage($model);
                $model->save();
            }
            $messages[] = ['muid' => $muid, 'mid' => (int) $model->m_id, 'model' => $model];
        }
        if ($messages === []) {
            return '<p class="{{@pclass}}">None of the selected messages currently has a target list.</p>';
        }

        $messageIds = array_column($messages, 'mid');
        $candidates = $this->advancedQueueCandidates($messageIds, $mvolume);
        $messageIndex = 0;
        $queued = 0;
        $messageCount = count($messages);

        foreach ($candidates as $candidate) {
            if (!$this->subscriber->RetrieveSubscriber((string) $candidate['s_uuid'])) {
                continue;
            }
            if ($this->subscriber->IsGloballySuppressed((string) $candidate['s_email'])) {
                $this->subscriber->UnsubscribeAllLists(
                    (int) $candidate['s_id'],
                    'Shared global suppression database'
                );
                continue;
            }

            for ($attempt = 0; $attempt < $messageCount; $attempt++) {
                $index = ($messageIndex + $attempt) % $messageCount;
                $entry = $messages[$index];
                $shortcode = $this->eligibleListShortcode(
                    (int) $candidate['s_id'],
                    (int) $entry['mid'],
                    (string) $candidate['s_uuid'],
                    (string) $entry['muid']
                );
                if ($shortcode === '') {
                    continue;
                }

                /** @var MessagesM $model */
                $model = $entry['model'];
                $inserted = $this->queue->AddToQueue(
                    (string) $entry['muid'],
                    (string) $candidate['s_uuid'],
                    stripslashes((string) $model->m_subject),
                    (string) $candidate['s_email'],
                    $shortcode,
                    $candidate['s_last_interacted'],
                    (int) $model->m_priority,
                    (int) $candidate['s_priority']
                );
                if (!$inserted) {
                    continue;
                }

                $model->m_queued = (int) $model->m_queued + 1;
                $model->save();
                $this->smlog->logMsgQueued((string) $candidate['s_uuid'], (string) $entry['muid'], $shortcode);
                $this->subscriber->subscriber->s_bounces = 0;
                $this->subscriber->subscriber->s_priority = 0;
                $this->subscriber->subscriber->s_emailsleft = max(0, (int) $this->subscriber->subscriber->s_emailsleft - 1);
                $this->subscriber->subscriber->save();

                $queued++;
                $messageIndex = ($index + 1) % $messageCount;
                break;
            }
            if ($queued >= $mvolume) {
                break;
            }
        }

        return '<p class="{{@pclass}}">Finished queueing: ' . $queued . '</p>';
    }

    public function messageWasSentToSubscriber(string $subscriberToken, string $muid): bool
    {
        $mapper = new SmlogM($this->fat);
        $mapper->load([
            'sml_s_uuid = :token AND sml_muid = :muid AND sml_date_sent IS NOT NULL',
            ':token' => strtolower(trim($subscriberToken)),
            ':muid' => trim($muid),
        ]);
        return $mapper->valid();
    }

    private function prepareMessageForQueue(): void
    {
        if ($this->template === null || $this->archive === null) {
            return;
        }
        if ($this->message->m_datesent === null || (string) $this->message->m_datesent === '') {
            $this->message->m_datesent = date('Y-m-d H:i:s');
            $this->message->save();
        }
        $this->template->RetrieveTemplate((int) $this->message->m_t_id);
        if ((int) $this->message->m_a_id === 0) {
            $this->message->m_a_id = $this->archive->CreateArchive();
            $this->message->save();
        }
    }

    /** @return list<array<string,mixed>> */
    private function eligibleSubscriberRows(int $messageId, string $muid, int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        return $this->dbPDO->exec(
            'WITH eligible AS (
                SELECT s.s_id, s.s_uuid, s.s_email, s.s_last_interacted,
                       s.s_priority, s.s_emailsleft, l.l_shortcode,
                       ROW_NUMBER() OVER (
                           PARTITION BY s.s_id
                           ORDER BY l.l_system DESC, l.l_shortcode ASC
                       ) AS audience_row
                FROM subscribers s
                JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
                JOIN lists l ON l.l_id = ls.ls_l_id
                JOIN message_lists ml ON ml.ml_l_id = l.l_id
                LEFT JOIN smlog sml
                  ON sml.sml_s_uuid = s.s_uuid AND sml.sml_muid = :muid
                WHERE ml.ml_m_id = :mid
                  AND ls.ls_confirmed = TRUE
                  AND ls.ls_unsubscribed = FALSE
                  AND l.l_active = TRUE
                  AND sml.sml_id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM queue q
                      WHERE q.q_muid = :muid AND q.q_s_uuid = s.s_uuid
                  )
            )
            SELECT s_id, s_uuid, s_email, s_last_interacted, s_priority,
                   s_emailsleft, l_shortcode
            FROM eligible
            WHERE audience_row = 1
            ORDER BY (s_last_interacted IS NULL) ASC,
                     s_last_interacted DESC,
                     s_priority DESC,
                     s_email ASC
            LIMIT ' . $limit,
            [':muid' => $muid, ':mid' => $messageId]
        );
    }

    /** @param list<int> $messageIds @return list<array<string,mixed>> */
    private function advancedQueueCandidates(array $messageIds, int $limit): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if ($messageIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($messageIds as $i => $id) {
            $key = ':mid' . $i;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $limit = max(1, $limit);
        return $this->dbPDO->exec(
            'SELECT s.s_id, s.s_uuid, s.s_email, s.s_last_interacted,
                    s.s_priority, s.s_emailsleft
             FROM subscribers s
             JOIN list_subscribers ls ON ls.ls_s_id = s.s_id
             JOIN lists l ON l.l_id = ls.ls_l_id
             JOIN message_lists ml ON ml.ml_l_id = l.l_id
             JOIN messages m ON m.m_id = ml.ml_m_id
             WHERE ml.ml_m_id IN (' . implode(',', $placeholders) . ')
               AND ls.ls_confirmed = TRUE
               AND ls.ls_unsubscribed = FALSE
               AND l.l_active = TRUE
               AND NOT EXISTS (
                   SELECT 1 FROM smlog existing_sml
                   WHERE existing_sml.sml_s_uuid = s.s_uuid
                     AND existing_sml.sml_muid = m.m_uniqid
               )
               AND NOT EXISTS (
                   SELECT 1 FROM queue existing_q
                   WHERE existing_q.q_s_uuid = s.s_uuid
                     AND existing_q.q_muid = m.m_uniqid
               )
             GROUP BY s.s_id
             ORDER BY (s.s_last_interacted IS NULL) ASC,
                      s.s_last_interacted DESC,
                      s.s_priority DESC,
                      s.s_email ASC
             LIMIT ' . $limit,
            $params
        );
    }

    private function eligibleListShortcode(int $subscriberId, int $messageId, string $subscriberToken, string $muid): string
    {
        if ($this->smlog->hasMessageRecord($subscriberToken, $muid)) {
            return '';
        }
        $queue = new QueueM($this->fat);
        $queue->load(['q_muid = :muid AND q_s_uuid = :token', ':muid' => $muid, ':token' => $subscriberToken]);
        if ($queue->valid()) {
            return '';
        }

        $rows = $this->dbPDO->exec(
            'SELECT l.l_shortcode
             FROM message_lists ml
             JOIN lists l ON l.l_id = ml.ml_l_id
             JOIN list_subscribers ls ON ls.ls_l_id = l.l_id
             WHERE ml.ml_m_id = :mid
               AND ls.ls_s_id = :sid
               AND ls.ls_confirmed = TRUE
               AND ls.ls_unsubscribed = FALSE
               AND l.l_active = TRUE
             ORDER BY l.l_system DESC, l.l_shortcode ASC
             LIMIT 1',
            [':mid' => $messageId, ':sid' => $subscriberId]
        );
        return (string) ($rows[0]['l_shortcode'] ?? '');
    }

    private function firstMessageListShortcode(int $messageId): string
    {
        $lists = $this->lists->messageLists($messageId);
        return (string) ($lists[0]['l_shortcode'] ?? '');
    }
}
