<?php

declare(strict_types=1);

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
    public function loadMid(int $mid): bool { return $this->message->loadByMid($mid); }
    public function CreateMUID(): string { return $this->message->CreateMUID(); }

    public function getSubject(string $muid): string
    {
        return $this->RetrieveMessage($muid) ? stripslashes((string) $this->message->m_subject) : '';
    }

    public function ClearMsgStats(string $muid): string
    {
        $this->message->clrmsgstats($muid);
        return '<p class="{{@pclass}}">Message statistics cleared.</p>';
    }

    /**
     * Create or edit a message. No field is made compulsory merely to save a
     * draft; send-readiness is an administrator decision made later.
     */
    public function CreateMessageHTMLform(string $muid = ''): string
    {
        if (!Controller::allowed($this->fat, 'messages.manage') || $this->template === null) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        if ($muid === '') {
            $legend = 'Create Message';
            $this->message->reset();
            $this->message->m_from_address = $this->FromAddress;
            $htmlBody = 'Enter your message here';
            $subject = 'Your subject here';
            $selectedLists = [];
        } else {
            if (!$this->RetrieveMessage($muid)) {
                return '<p class="{{@pclass}}">Message does not exist.</p>';
            }
            $legend = 'Edit Message';
            $htmlBody = (string) $this->message->m_html;
            $subject = (string) $this->message->m_subject;
            $selectedLists = $this->lists->messageListIds((int) $this->message->m_id);
        }

        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = Csrf::field($this->fat);
        $templateOptions = $this->template->CreateTemplateHTMLDropDown((int) $this->message->m_t_id);
        $listCheckboxes = $this->subscriber->CreateListHTMLCheckboxes($selectedLists);

        return <<<HTML
<form action="{{@BaseURL}}message" method="post">
  {$csrf}
  <input type="hidden" name="m_uniqid" value="{$e($muid)}">
  <fieldset class="border rounded p-4">
    <legend>{$legend}</legend>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Template</label><select class="form-select" name="m_t_id">{$templateOptions}</select></div>
      <div class="col-md-6"><label class="form-label">From name</label><input class="form-control" name="m_from_name" value="{$e($this->message->m_from_name)}"></div>
      <div class="col-md-6"><label class="form-label">From address</label><input class="form-control" type="text" name="m_from_address" value="{$e($this->message->m_from_address)}"></div>
      <div class="col-md-3"><label class="form-label">Priority</label><input class="form-control" type="number" name="m_priority" value="{$e($this->message->m_priority)}"></div>
      <div class="col-md-3"><label class="form-label">Maximum sends</label><input class="form-control" type="number" name="m_max_send" value="{$e($this->message->m_max_send)}"></div>
      <div class="col-12"><label class="form-label">Send to lists</label>{$listCheckboxes}<div class="form-text">A message may be saved with no list selected and assigned to one or more lists later.</div></div>
      <div class="col-12"><label class="form-label">Subject</label><input class="form-control" name="m_subject" value="{$e($subject)}"></div>
      <div class="col-12"><label class="form-label" for="mt_html">HTML part</label><textarea class="form-control" id="mt_html" name="mt_html" style="width:100%;min-height:500px">{$e($htmlBody)}</textarea></div>
      <div class="col-12"><label class="form-label">Text part</label><textarea class="form-control" name="m_text" rows="15">{$e($this->message->m_text)}</textarea></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Save Message</button></div>
    </div>
  </fieldset>
</form>
HTML;
    }

    /** Save exactly what the administrator supplied, including incomplete drafts. */
    public function save(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'messages.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        $muid = trim((string) $this->fat->get('POST.m_uniqid'));
        if ($muid === '') {
            $this->message->reset();
            $muid = $this->CreateMUID();
            $this->message->m_uniqid = $muid;
        } elseif (!$this->RetrieveMessage($muid)) {
            return '<p class="{{@pclass}}">Message does not exist.</p>';
        }

        // Deliberately do not invent defaults, clamp values or refuse an
        // incomplete draft. The administrator owns the message state.
        $this->message->m_t_id = (int) $this->fat->get('POST.m_t_id');
        $this->message->m_from_name = (string) $this->fat->get('POST.m_from_name');
        $this->message->m_from_address = (string) $this->fat->get('POST.m_from_address');
        $this->message->m_subject = (string) $this->fat->get('POST.m_subject');
        $this->message->m_priority = (int) $this->fat->get('POST.m_priority');
        $this->message->m_max_send = (int) $this->fat->get('POST.m_max_send');
        $this->message->m_html = (string) $this->fat->get('POST.mt_html');
        $this->message->m_text = (string) $this->fat->get('POST.m_text');
        $this->message->save();

        $messageId = (int) ($this->message->m_id ?: $this->message->_id);
        $listIds = array_values(array_unique(array_map('intval', (array) $this->fat->get('POST.list_ids'))));
        $this->lists->saveMessageLists($messageId, $listIds);
        return '<p class="{{@pclass}}">Message saved.</p>';
    }

    /**
     * Restored operational message list: edit, activity, queue, process queue
     * and proof/test controls are all visible from the normal admin workflow.
     */
    public function CreateMessagesHTMLList(int $pageNo = 1, int $numRows = 25, bool $saved = false): string
    {
        if (!Controller::allowed($this->fat, 'messages.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        $total = (int) $this->message->count();
        if ($total === 0) {
            return '<p class="{{@pclass}}">There are no messages.</p>';
        }

        $pageNo = max(1, $pageNo);
        $numRows = max(1, min(200, $numRows));
        $page = $this->message->paginate($pageNo - 1, $numRows, null, ['order' => 'm_id DESC']);
        $html = $saved ? '<div class="alert alert-info">Message saved.</div>' : '';
        $html .= '<div class="table-responsive"><table class="table table-striped table-hover table-bordered">'
            . '<thead><tr><th>Subject</th><th>Lists</th><th>Sent</th><th>Q:S</th>'
            . '<th>Last read</th><th>Last like</th><th>Reads</th><th>Likes</th><th>Dislikes</th>'
            . '<th>Add2Q</th><th>SendQ</th><th>Proof</th></tr></thead><tbody>';

        foreach ($page['subset'] as $row) {
            $muid = (string) $row['m_uniqid'];
            $fullSubject = stripslashes((string) $row['m_subject']);
            $shortSubject = mb_substr($fullSubject, 0, 60) . (mb_strlen($fullSubject) > 60 ? '…' : '');
            $subjectLink = '<a href="{{@BaseURL}}message/' . rawurlencode($muid) . '" title="'
                . htmlspecialchars($fullSubject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($shortSubject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

            $listNames = array_map(
                static fn(array $list): string => (string) $list['l_name'],
                $this->lists->messageLists((int) $row['m_id'])
            );
            $lists = $listNames === [] ? '<em>None selected</em>' : htmlspecialchars(implode(', ', $listNames));
            $activity = '<a href="{{@BaseURL}}message-views/' . rawurlencode($muid) . '">' . (int) $row['m_reads'] . '</a>';
            $queue = '<a class="btn btn-sm u-btn-deeporange" href="{{@BaseURL}}queuelist/' . rawurlencode($muid) . '">Queue</a>';
            $sendQueue = '<a class="btn btn-sm u-btn-yellow" href="{{@BaseURL}}processqueue/' . rawurlencode($muid) . '">SendQ</a>';
            $proof = '<a class="btn btn-sm u-btn-indigo" href="{{@BaseURL}}sendtome/' . rawurlencode($muid) . '">Proof</a>';

            $html .= '<tr>'
                . '<td>' . $subjectLink . '</td>'
                . '<td>' . $lists . '</td>'
                . '<td>' . htmlspecialchars((string) $row['m_datesent']) . '</td>'
                . '<td>' . (int) $row['m_queued'] . ':' . (int) $row['m_sent'] . '</td>'
                . '<td>' . htmlspecialchars((string) $row['m_last_read']) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['m_last_like']) . '</td>'
                . '<td>' . $activity . '</td>'
                . '<td>' . (int) $row['m_likes'] . '</td>'
                . '<td>' . (int) $row['m_dislikes'] . '</td>'
                . '<td>' . $queue . '</td><td>' . $sendQueue . '</td><td>' . $proof . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html . (new htmlhelper($this->fat))->paginate($page, 'messages');
    }

    public function CreateForwardHTMLform(string $subscriberToken, string $muid): string
    {
        $admin = Controller::allowed($this->fat, 'messages.manage');
        if (!$admin && !$this->messageWasSentToSubscriber($subscriberToken, $muid)) {
            $this->fat->error(404);
        }
        $csrf = Csrf::field($this->fat);
        $token = htmlspecialchars($subscriberToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $muidEscaped = htmlspecialchars($muid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<HTML
<form action="{{@BaseURL}}forward" method="post" class="card card-body">
  {$csrf}
  <input type="hidden" name="subscriber_token" value="{$token}">
  <input type="hidden" name="muid" value="{$muidEscaped}">
  <h1 class="h4">Forward message</h1>
  <p>Enter up to 10 email addresses.</p>
  <div class="mb-3"><label class="form-label">Email addresses</label><textarea class="form-control" name="bemail" rows="8" required></textarea></div>
  <button class="btn btn-primary" type="submit">Forward current message</button>
</form>
HTML;
    }

    /**
     * Forward campaign content through the campaign-send path so sendlog and
     * smlog are both maintained for every recipient.
     */
    public function ForwardSubscribeMessage(string $subscriberToken, string $muid, string $input): string
    {
        Csrf::requireValid($this->fat);
        $administrator = Controller::allowed($this->fat, 'messages.manage');
        if (
            $this->mailer === null
            || $this->template === null
            || (!$administrator && !$this->messageWasSentToSubscriber($subscriberToken, $muid))
        ) {
            $this->fat->error(404);
        }
        if (!$this->subscriber->RetrieveSubscriber($subscriberToken) || !$this->RetrieveMessage($muid)) {
            $this->fat->error(404);
        }

        $senderId = (int) $this->subscriber->subscriber->s_id;
        $senderEmail = (string) $this->subscriber->subscriber->s_email;
        $senderName = trim((string) $this->subscriber->subscriber->s_fname . ' ' . (string) $this->subscriber->subscriber->s_lname);
        $sourceList = $this->smlog->listShortcode($subscriberToken, $muid);
        if ($sourceList === '') {
            // Administrator-initiated forwards may have no prior delivery row.
            // In that case use an explicitly assigned message list, or blank.
            $sourceList = $this->firstMessageListShortcode((int) $this->message->m_id);
        }
        $emails = $this->subscriber->find_email_addresses($input);
        if ($emails === []) {
            return '<p class="{{@pclass}}">No valid email addresses were supplied.</p>';
        }
        if (count($emails) > 10) {
            return '<p class="{{@pclass}}">A message may be forwarded to no more than 10 unique addresses at a time.</p>';
        }

        $successful = [];
        foreach ($emails as $email) {
            $domain = $this->subscriber->subscriber->getEmailDomain($email);
            if ($this->subscriber->gu->IsUnsubscribed($email) || $this->subscriber->gdu->IsUnsubscribed($domain)) {
                continue;
            }

            // Identity creation does not grant list consent. The direct forward
            // itself is sent because the existing subscriber explicitly asked
            // the application to forward this particular message.
            $this->subscriber->SimpleSubscribe($email, 0, ListsM::ALL_SHORTCODE);
            if (!$this->subscriber->LoadSubscriber($email)) {
                continue;
            }
            $recipientId = (int) $this->subscriber->subscriber->s_id;
            // A forwarded copy retains the list context of the copy received
            // by the person who initiated the forward.
            $sent = $this->SendToAddress($muid, $email, 'FORWARD-MESSAGE', $sourceList);
            if ($sent < 1) {
                continue;
            }

            $forward = new MessageForwardsM($this->fat);
            $forward->mf_m_id = (int) $this->message->m_id;
            $forward->mf_sender_s_id = $senderId;
            $forward->mf_recipient_s_id = $recipientId;
            $forward->mf_recipient_email = $email;
            $forward->save();

            // v5 counted one forward per successful recipient.
            $this->smlog->logMsgForward($subscriberToken, $muid);
            $this->subscriber->bumpPriority($subscriberToken, 5);
            $successful[] = $email;
        }

        // Restore the action notification to the initiating subscriber. The
        // mailer BCCs the administrator and writes the notification to sendlog.
        if ($successful !== [] && $this->subscriber->RetrieveSubscriber($subscriberToken) && $this->mailer->OpenSMTP()) {
            $subject = $senderEmail . ' forwarded ' . $muid;
            $recipientText = implode(', ', $successful);
            $html = '<p>You forwarded the message <strong>' . htmlspecialchars($muid) . '</strong> to:</p><p>'
                . htmlspecialchars($recipientText) . '</p>';
            $text = "You forwarded message {$muid} to: {$recipientText}\n";
            try {
                $this->mailer->SendNotification(
                    $muid,
                    'FORWARD-NOTIFICATION',
                    (string) $this->message->m_from_address,
                    $senderEmail,
                    $senderName,
                    $subject,
                    $html,
                    $text,
                    $subscriberToken,
                    $sourceList
                );
            } finally {
                $this->mailer->CloseSMTP();
            }
        }

        return '<p class="{{@pclass}}">Forwarded the current message to ' . count($successful) . ' recipient(s).</p>';
    }

    /** Restore archive forwarding from v5.0. */
    public function ForwardSubscribeArchive(): string
    {
        Csrf::requireValid($this->fat);
        $aid = (int) $this->fat->get('POST.aid');
        $subscriberToken = (string) $this->fat->get('POST.subscriber_token');
        $muid = trim((string) $this->fat->get('POST.muid'));
        $emails = (string) $this->fat->get('POST.bemail');

        if ($muid === '') {
            if (!$this->loadMessage($aid)) {
                return '<p class="{{@pclass}}">Cannot find the message associated with this archive.</p>';
            }
            $muid = (string) $this->message->m_uniqid;
        }
        return $this->ForwardSubscribeMessage($subscriberToken, $muid, $emails);
    }

    public function CreateResendHTMLform(string $subscriberToken, string $muid): string
    {
        if (!$this->messageWasSentToSubscriber($subscriberToken, $muid)) {
            $this->fat->error(404);
        }
        $csrf = Csrf::field($this->fat);
        return '<form action="{{@BaseURL}}resend" method="post" class="card card-body">' . $csrf
            . '<input type="hidden" name="subscriber_token" value="' . htmlspecialchars($subscriberToken) . '">'
            . '<input type="hidden" name="muid" value="' . htmlspecialchars($muid) . '">'
            . '<h1 class="h4">Send this message again</h1>'
            . '<p>The current version will be sent; it may differ from the archived version first received.</p>'
            . '<button class="btn btn-primary" type="submit">Send current version</button></form>';
    }

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

    /** Track an open, retaining compatibility with old numeric message IDs. */
    public function TrackOpen(string $subscriberToken, string $muid): void
    {
        $resolvedMuid = $this->resolveMessageMuid($muid);
        if ($resolvedMuid === '' || !$this->messageWasSentToSubscriber($subscriberToken, $resolvedMuid)) {
            return;
        }
        if (!$this->RetrieveMessage($resolvedMuid)) {
            return;
        }

        $this->message->m_reads = (int) $this->message->m_reads + 1;
        $this->message->m_last_read = date('Y-m-d H:i:s');
        $this->message->save();
        $this->subscriber->bumpPriority($subscriberToken);
        $this->smlog->logMsgRead($subscriberToken, $resolvedMuid);
    }

    public function CreateReactionHTMLform(string $subscriberToken, string $muid, string $reaction): string
    {
        if (!$this->messageWasSentToSubscriber($subscriberToken, $muid) || !in_array($reaction, ['like', 'dislike'], true)) {
            $this->fat->error(404);
        }
        $csrf = Csrf::field($this->fat);
        return '<form action="{{@BaseURL}}' . $reaction . '" method="post" class="card card-body">' . $csrf
            . '<input type="hidden" name="subscriber_token" value="' . htmlspecialchars($subscriberToken) . '">'
            . '<input type="hidden" name="muid" value="' . htmlspecialchars($muid) . '">'
            . '<h1 class="h4">' . ucfirst($reaction) . ' this message</h1>'
            . '<button class="btn btn-primary" type="submit">Confirm</button></form>';
    }

    public function like(string $subscriberToken, string $muid): string
    {
        Csrf::requireValid($this->fat);
        if (!$this->messageWasSentToSubscriber($subscriberToken, $muid) || !$this->RetrieveMessage($muid)) {
            $this->fat->error(404);
        }
        $date = date('Y-m-d H:i:s');
        $this->message->m_reads = (int) $this->message->m_reads + 1;
        $this->message->m_last_read = $date;
        $this->message->m_likes = (int) $this->message->m_likes + 1;
        $this->message->m_last_like = $date;
        $this->message->save();
        $this->subscriber->bumpPriority($subscriberToken, 1000);
        $this->smlog->logMsgLike($subscriberToken, $muid);
        return '<p class="{{@pclass}}">Thank you for your LIKE.</p>';
    }

    public function dislike(string $subscriberToken, string $muid): string
    {
        Csrf::requireValid($this->fat);
        if (!$this->messageWasSentToSubscriber($subscriberToken, $muid) || !$this->RetrieveMessage($muid)) {
            $this->fat->error(404);
        }
        $date = date('Y-m-d H:i:s');
        $this->message->m_reads = (int) $this->message->m_reads + 1;
        $this->message->m_last_read = $date;
        $this->message->m_dislikes = (int) $this->message->m_dislikes + 1;
        $this->message->m_last_dislike = $date;
        $this->message->save();
        $this->subscriber->ResetPriority($subscriberToken);
        $this->smlog->logMsgDislike($subscriberToken, $muid);
        return '<p class="{{@pclass}}">We apologise for the lack of relevance of our message to you.</p>';
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
            if ((int) $model->m_a_id === 0 && $this->archive !== null) {
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

    private function resolveMessageMuid(string $muidOrId): string
    {
        $muidOrId = trim($muidOrId);
        if ($this->RetrieveMessage($muidOrId)) {
            return (string) $this->message->m_uniqid;
        }
        if (ctype_digit($muidOrId) && $this->loadMid((int) $muidOrId)) {
            return (string) $this->message->m_uniqid;
        }
        return '';
    }
}
