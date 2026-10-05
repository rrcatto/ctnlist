<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;
use Generator;

/**
 * Subscriber identity, profile and per-list consent controller.
 *
 * v5.0 profile, bulk-management, import/export and notification workflows are
 * retained. v5.0.1 stores consent per list and uses the subscriber UUIDv7 as
 * the public identity.
 */
class SubscribersController extends Controller
{
    protected Base $fat;
    protected \DB\SQL $dbPDO;
    protected string $BaseURL;
    protected string $ListName;
    protected string $FromAddress;
    protected string $Domain;
    protected ListService $lists;

    public SubscribersM $subscriber;
    public SmlogController $smlog;
    public ?mailer $mailer = null;
    public GlobalUnsubscribeController $gu;
    public GlobalDomainUnsubscribeController $gdu;

    public string $find_email_preg = '/\b([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,63})\b/i';

    public function __construct(Base $fat, SmlogController $smlog)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->ListName = (string) $fat->get('ListName');
        $this->FromAddress = (string) $fat->get('FromAddress');
        $this->Domain = (string) $fat->get('Domain');
        $this->smlog = $smlog;
        $this->subscriber = new SubscribersM($fat);
        $this->lists = new ListService($fat, $this->dbPDO);
        $this->gu = new GlobalUnsubscribeController($fat);
        $this->gdu = new GlobalDomainUnsubscribeController($fat);
    }

    public function SetMailer(mailer $mailer): void { $this->mailer = $mailer; }

    /** @return list<string> */
    public function find_email_addresses(string $input): array
    {
        if (!preg_match_all($this->find_email_preg, $input, $matches)) {
            return [];
        }
        $emails = array_map([SubscribersM::class, 'normaliseEmail'], $matches[1]);
        $emails = array_filter($emails, [SubscribersM::class, 'validEmail']);
        return array_values(array_unique($emails));
    }

    public function NumSubscribers(): int { return $this->subscriber->numsubscribers(); }
    public function ActiveReaders(): int { return $this->subscriber->activeReaders(); }
    public function Confirmed(): int { return $this->subscriber->confirmed(); }
    public function Unsubscribed(): int { return $this->subscriber->unsubscribed(); }
    public function RetrieveSubscriber(string $token): bool { return $this->subscriber->read($token); }
    public function LoadSubscriber(string $email): bool { return $this->subscriber->loadByEmail($email); }
    public function IsSubscribed(string $email): bool { return $this->subscriber->isSubscribed($email); }
    public function getEmail(string $token): string { return $this->subscriber->getEmail($token); }
    public function bumpPriority(string $token, int $amount = 1): bool { return $this->subscriber->bumpPriority($token, $amount); }
    public function ResetPriority(string $token): bool { return $this->subscriber->resetPriority($token); }
    public function setPriority(string $token, int $priority): bool { return $this->subscriber->setPriority($token, $priority); }

    /**
     * The shared suppression database remains authoritative across every local
     * list. Keep this check in the subscriber service so queue, confirmation
     * and import workflows all use the same rule.
     */
    public function IsGloballySuppressed(string $email): bool
    {
        $email = $this->subscriber->normaliseCandidateEmail($email);
        if (!SubscribersM::validEmail($email)) {
            return true;
        }
        $domain = $this->subscriber->getEmailDomain($email);
        return $this->gu->IsUnsubscribed($email) || $this->gdu->IsUnsubscribed($domain);
    }

    /**
     * Synchronise local list eligibility with a shared global suppression.
     * This replaces the single v5 s_unsubscribe flag in the multi-list schema.
     */
    public function UnsubscribeAllLists(int $subscriberId, string $reason = ''): void
    {
        if ($subscriberId > 0) {
            $this->lists->unsubscribeAll($subscriberId, $reason);
        }
    }

    /** Restore the old offset/amount export operation. */
    public function export(int $offset, int $amount): string
    {
        if (!Controller::allowed($this->fat, 'subscribers.view')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        return $this->writeExportFile(
            $this->subscriber->exportEmails(true, $offset, $amount),
            'export.txt',
            true
        );
    }

    /** @param list<string> $emails */
    private function writeExportFile(array $emails, string $filename, bool $append = false): string
    {
        $logs = rtrim((string) $this->fat->get('LOGS'), DIRECTORY_SEPARATOR);
        if ($logs === '') {
            $logs = '.';
        }
        if (!is_dir($logs)) {
            mkdir($logs, 0770, true);
        }
        $path = $logs . DIRECTORY_SEPARATOR . basename($filename);
        $handle = fopen($path, $append ? 'ab' : 'wb');
        if ($handle === false) {
            return '<p class="{{@pclass}}">Unable to create export file.</p>';
        }
        foreach ($emails as $email) {
            fwrite($handle, $email . PHP_EOL);
        }
        fclose($handle);
        return '<p class="{{@pclass}}">' . htmlspecialchars(basename($filename)) . ' - export complete (' . count($emails) . ' addresses).</p>';
    }

    /**
     * Restores the two v5 export files using per-list consent semantics.
     */
    public function exportPDO(int $offset = 0, int $limit = 10000000, int $listId = 0): string
    {
        if (!Controller::allowed($this->fat, 'subscribers.view')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $html = $this->writeExportFile(
            $this->subscriber->exportEmails(true, $offset, $limit, $listId),
            'export-subscribers.txt'
        );
        $html .= $this->writeExportFile(
            $this->subscriber->exportEmails(false, $offset, $limit, $listId),
            'export-remove.txt'
        );
        return $html;
    }

    public function CreateSubscriberHTMLform(string $token = '', string $muid = ''): string
    {
        $admin = Controller::allowed($this->fat, 'subscribers.manage');
        $loggedInToken = (string) $this->fat->get('SESSION.suid');
        if ($token !== '' && !$admin && !hash_equals($loggedInToken, $token)) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        if ($token !== '') {
            if (!$this->RetrieveSubscriber($token)) {
                return '<p class="{{@pclass}}">The subscriber does not exist.</p>';
            }
            $legend = 'Edit Subscriber';
            $emailState = ' readonly';
        } else {
            if (!$admin) {
                return '<p class="{{@pclass}}">Use the sign-in form to create an account.</p>';
            }
            $this->subscriber->reset();
            $legend = 'Add New Subscriber';
            $emailState = '';
        }

        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = Csrf::field($this->fat);
        $gender = (new formfield())->CreateGenderHTMLDropDown((string) $this->subscriber->s_gender);
        $province = (new formfield())->CreateProvinceHTMLDropDown((string) $this->subscriber->s_province);
        $country = (new formfield())->CreateCountryHTMLDropDown((string) $this->subscriber->s_country);
        $listOptions = $this->CreateListHTMLDropDown(ListsM::ALL_SHORTCODE);
        $membershipHtml = '';
        if ($token !== '') {
            $membershipHtml = '<div class="col-12"><h2 class="h5">List memberships</h2><ul>';
            foreach ($this->lists->memberships((int) $this->subscriber->s_id) as $membership) {
                $state = empty($membership['ls_uuid'])
                    ? 'not joined'
                    : ((bool) $membership['ls_unsubscribed'] ? 'unsubscribed' : ((bool) $membership['ls_confirmed'] ? 'confirmed' : 'pending'));
                $membershipHtml .= '<li>' . $e($membership['l_name']) . ' (' . $e($membership['l_shortcode']) . '): ' . $e($state) . '</li>';
            }
            $membershipHtml .= '</ul></div>';
        }

        $adminFields = '';
        if ($admin) {
            $adminFields = '<div class="col-md-6"><label class="form-label">Add to list</label><select class="form-select" name="list_id">'
                . $listOptions . '</select></div>'
                . '<div class="col-md-6"><label class="form-label">Priority</label><input class="form-control" type="number" name="s_priority" value="'
                . $e($this->subscriber->s_priority) . '"></div>';
        }

        return <<<HTML
<form action="{{@BaseURL}}subscribe" method="post">
  {$csrf}
  <input type="hidden" name="subscriber_token" value="{$e($token)}">
  <input type="hidden" name="muid" value="{$e($muid)}">
  <fieldset class="border rounded p-4">
    <legend>{$legend}</legend>
    <div class="row g-3">
      {$adminFields}
      <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" name="s_fname" value="{$e($this->subscriber->s_fname)}"></div>
      <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" name="s_lname" value="{$e($this->subscriber->s_lname)}"></div>
      <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="s_email" value="{$e($this->subscriber->s_email)}"{$emailState}></div>
      <div class="col-md-6"><label class="form-label">Gender</label><select class="form-select" name="s_gender">{$gender}</select></div>
      <div class="col-md-6"><label class="form-label">Province</label><select class="form-select" name="s_province">{$province}</select></div>
      <div class="col-md-6"><label class="form-label">Country</label><select class="form-select" name="s_country">{$country}</select></div>
      {$membershipHtml}
      <div class="col-12"><button class="btn btn-primary" type="submit">Save Subscriber</button></div>
    </div>
  </fieldset>
</form>
HTML;
    }

    /** Save a subscriber and restore the established transactional notification. */
    public function save(): string
    {
        Csrf::requireValid($this->fat);
        $token = strtolower(trim((string) $this->fat->get('POST.subscriber_token')));
        $muid = trim((string) $this->fat->get('POST.muid'));
        $admin = Controller::allowed($this->fat, 'subscribers.manage');
        $loggedInToken = (string) $this->fat->get('SESSION.suid');
        $new = $token === '';

        if ($new) {
            if (!$admin) {
                return '<p class="{{@pclass}}">Access denied.</p>';
            }
            $email = SubscribersM::normaliseEmail((string) $this->fat->get('POST.s_email'));
            if (!SubscribersM::validEmail($email)) {
                return '<p class="{{@pclass}}">Enter a valid email address.</p>';
            }
            if (!$this->SimpleSubscribe($email, (int) $this->fat->get('POST.s_priority'), (int) $this->fat->get('POST.list_id'))) {
                return '<p class="{{@pclass}}">The subscriber could not be added.</p>';
            }
            $this->subscriber->loadByEmail($email);
            $token = (string) $this->subscriber->s_uuid;
            if ($muid !== '') {
                // Preserve the v5 message-linked subscription activity record.
                $this->smlog->logMsgSub($token, $muid);
            }
        } else {
            if (!$admin && !hash_equals($loggedInToken, $token)) {
                return '<p class="{{@pclass}}">Access denied.</p>';
            }
            if (!$this->RetrieveSubscriber($token)) {
                return '<p class="{{@pclass}}">Invalid subscriber identifier.</p>';
            }
        }

        $this->subscriber->s_fname = trim((string) $this->fat->get('POST.s_fname'));
        $this->subscriber->s_lname = trim((string) $this->fat->get('POST.s_lname'));
        $this->subscriber->s_gender = trim((string) $this->fat->get('POST.s_gender'));
        $this->subscriber->s_province = trim((string) $this->fat->get('POST.s_province'));
        $this->subscriber->s_country = trim((string) $this->fat->get('POST.s_country'));
        if ($admin) {
            $this->subscriber->s_priority = (int) $this->fat->get('POST.s_priority');
            $listId = (int) $this->fat->get('POST.list_id');
            if ($listId > 0) {
                $createdMembership = $this->lists->ensureMembership((int) $this->subscriber->s_id, $listId);
                if ($createdMembership) {
                    $this->sendMembershipInvitation($listId);
                }
            }
        }
        $this->subscriber->save();

        // Preserve the v5.0 profile-save engagement update. For a normal
        // subscriber the priority field is absent, so the established result
        // is 100; an administrator-supplied priority is increased by 100.
        $this->setPriority($token, (int) $this->fat->get('POST.s_priority') + 100);

        if (!$new) {
            if ($muid !== '') {
                $this->smlog->logMsgUpdate($token, $muid);
            }
            $this->sendProfileNotification($token, $muid, 'UPDATE-PROFILE');
        }

        return '<p class="{{@pclass}}">Subscriber saved.</p>';
    }

    /**
     * Create the identity and pending membership. This method never silently
     * grants bulk-mail consent.
     */
    public function SimpleSubscribe(string $email, int $priority = 0, int|string $list = ListsM::ALL_SHORTCODE): bool
    {
        $email = $this->subscriber->normaliseCandidateEmail($email);
        if (!SubscribersM::validEmail($email)) {
            return false;
        }
        $domain = $this->subscriber->getEmailDomain($email);
        if ($this->gu->IsUnsubscribed($email) || $this->gdu->IsUnsubscribed($domain)) {
            return false;
        }

        $listId = $this->resolveListId($list);
        if ($listId < 1) {
            return false;
        }

        $identityCreated = false;
        if (!$this->subscriber->loadByEmail($email)) {
            if (!$this->subscriber->createIdentity($email)) {
                return false;
            }
            $identityCreated = true;
        }

        $this->subscriber->s_priority = max((int) $this->subscriber->s_priority, min($priority, 10000000));
        $this->subscriber->save();

        $membershipCreated = $this->lists->ensureMembership((int) $this->subscriber->s_id, $listId);

        // The subscriber insert trigger creates a pending ALL membership. Send
        // only one invitation per newly relevant membership.
        if ($identityCreated) {
            $all = $this->lists->findByShortcode(ListsM::ALL_SHORTCODE);
            if ($all !== null) {
                $this->sendMembershipInvitation((int) $all['l_id']);
            }
        }
        if ($membershipCreated) {
            $target = $this->lists->findById($listId);
            if ($target !== null && (!$identityCreated || (string) $target['l_shortcode'] !== ListsM::ALL_SHORTCODE)) {
                $this->sendMembershipInvitation($listId);
            }
        }

        return true;
    }

    private function sendMembershipInvitation(int $listId): void
    {
        if ($this->mailer === null) {
            return;
        }
        $list = $this->lists->findById($listId);
        if ($list === null || !$this->subscriber->valid() || !$this->mailer->OpenSMTP()) {
            return;
        }
        try {
            $this->mailer->SendListConfirmationInvitation(
                (string) $this->subscriber->s_email,
                (string) $this->subscriber->s_uuid,
                (string) $list['l_shortcode'],
                (string) $list['l_name']
            );
        } finally {
            $this->mailer->CloseSMTP();
        }
    }

    public function CreateListHTMLDropDown(int|string $selected = ListsM::ALL_SHORTCODE, bool $includeBlank = false): string
    {
        $selectedId = is_int($selected) || ctype_digit((string) $selected)
            ? (int) $selected
            : (int) (($this->lists->findByShortcode((string) $selected)['l_id'] ?? 0));
        $html = $includeBlank ? '<option value="">All lists</option>' : '';
        foreach ($this->lists->all() as $list) {
            $id = (int) $list['l_id'];
            $html .= '<option value="' . $id . '"' . ($id === $selectedId ? ' selected' : '') . '>'
                . htmlspecialchars((string) $list['l_name']) . ' (' . htmlspecialchars((string) $list['l_shortcode']) . ')</option>';
        }
        return $html;
    }

    /** Restored subscriber search, filtering and pagination. */
    public function CreateSubscribersHTMLList(
        string $searchEmail = '',
        int $pageNo = 1,
        int $numRows = 25,
        bool $activeOnly = false,
        int $includeUnsubscribed = 0,
        int $listId = 0
    ): string {
        if (!Controller::allowed($this->fat, 'subscribers.view')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        $numRows = max(1, min(200, $numRows));
        $total = $this->subscriber->reportCount($searchEmail, $activeOnly, (bool) $includeUnsubscribed, $listId);
        $csrf = Csrf::field($this->fat);
        $html = '<form method="get" action="{{@BaseURL}}' . ($activeOnly ? 'activesubscribers' : 'subscribers') . '" class="row g-3 mb-4">'
            . '<div class="col-md-4"><label class="form-label">Email address</label><input class="form-control" type="search" name="e" value="'
            . htmlspecialchars($searchEmail) . '"></div>'
            . '<div class="col-md-3"><label class="form-label">List</label><select class="form-select" name="l">'
            . $this->CreateListHTMLDropDown($listId, true) . '</select></div>'
            . '<div class="col-md-2"><label class="form-label">Rows</label><input class="form-control" type="number" name="r" value="' . $numRows . '"></div>'
            . '<div class="col-md-2 form-check align-self-end mb-2"><input class="form-check-input" type="checkbox" name="u" value="1" id="include-unsubscribed"'
            . ($includeUnsubscribed ? ' checked' : '') . '><label class="form-check-label" for="include-unsubscribed">Include unsubscribed</label></div>'
            . '<div class="col-md-1 align-self-end"><button class="btn btn-primary" type="submit">Search</button></div></form>';

        if ($total === 0) {
            return $html . '<p class="{{@pclass}}">There are no matching subscribers.</p>';
        }
        $lastPage = max(1, (int) ceil($total / $numRows));
        $pageNo = max(1, min($pageNo, $lastPage));
        $rows = $this->subscriber->reportPage(
            $searchEmail,
            ($pageNo - 1) * $numRows,
            $numRows,
            $activeOnly,
            (bool) $includeUnsubscribed,
            $listId
        );

        $html .= '<div class="table-responsive"><table class="table table-striped table-hover table-bordered">'
            . '<thead><tr><th>Email</th><th>Name</th><th>Lists</th><th>Priority</th><th>Bounces</th><th>Emails left</th><th>Last interaction</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $token = rawurlencode((string) $row['s_uuid']);
            $html .= '<tr><td><a href="{{@BaseURL}}subscribe/' . $token . '">' . htmlspecialchars((string) $row['s_email']) . '</a></td>'
                . '<td>' . htmlspecialchars(trim((string) $row['s_fname'] . ' ' . (string) $row['s_lname'])) . '</td>'
                . '<td>' . htmlspecialchars((string) $row['memberships']) . '</td>'
                . '<td>' . (int) $row['s_priority'] . '</td><td>' . (int) $row['s_bounces'] . '</td>'
                . '<td>' . (int) $row['s_emailsleft'] . '</td><td>' . htmlspecialchars((string) $row['s_last_interacted']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $page = ['pos' => $pageNo - 1, 'count' => $lastPage, 'total' => $total];
        $query = '?e=' . rawurlencode($searchEmail) . '&r=' . $numRows . '&u=' . ($includeUnsubscribed ? 1 : 0) . '&l=' . $listId;
        return $html . (new htmlhelper($this->fat))->paginate($page, $activeOnly ? 'activesubscribers' : 'subscribers', $query);
    }

    public function CreateBulkSubscribeHTMLform(): string
    {
        if (!Controller::allowed($this->fat, 'subscribers.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        return '<form action="{{@BaseURL}}bulk-subscribe" method="post">' . Csrf::field($this->fat)
            . '<fieldset class="border rounded p-4"><legend>Subscribe multiple emails</legend>'
            . '<div class="mb-3"><label class="form-label">List</label><select class="form-select" name="list_id">'
            . $this->CreateListHTMLDropDown(ListsM::ALL_SHORTCODE) . '</select></div>'
            . '<div class="mb-3"><label class="form-label">Priority</label><input class="form-control" type="number" name="s_priority" value="10"></div>'
            . '<div class="mb-3"><label class="form-label">Email addresses</label><textarea class="form-control" rows="12" name="bemail"></textarea></div>'
            . '<button class="btn btn-primary" type="submit">Subscribe Emails</button></fieldset></form>';
    }

    public function BulkSubscribeForm(string|array $input, int $priority = 0, int|string $list = ListsM::ALL_SHORTCODE, bool $debug = true, bool $validateCsrf = true): string
    {
        if ($validateCsrf) {
            Csrf::requireValid($this->fat);
        }
        set_time_limit(86400);
        $text = is_array($input) ? implode("\n", $input) : $input;
        $emails = $this->find_email_addresses($text);
        $processed = 0;
        $addedLog = [];
        foreach ($emails as $email) {
            if ($this->SimpleSubscribe($email, $priority, $list)) {
                $processed++;
                $addedLog[] = $email;
            }
        }
        $this->appendImportLog('emails_added.txt', $addedLog, 'Number subscribed: ' . $processed);
        return '<p class="{{@pclass}}">Number subscribed: ' . $processed . '</p>';
    }

    public function BulkSubscribe(string $filename, int $priority = 0, int|string $list = ListsM::ALL_SHORTCODE, bool $debug = false): string
    {
        if (!is_file($filename)) {
            return '<p class="{{@pclass}}">Import file does not exist.</p>';
        }
        return $this->BulkSubscribeForm(file($filename, FILE_IGNORE_NEW_LINES) ?: [], $priority, $list, $debug, false);
    }

    /** @return Generator<int,string> */
    public function GetLineFromFile(string $filename): Generator
    {
        $handle = fopen($filename, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    public function BulkSubScribeFile(string $filename, int $priority = 0, int|string $list = ListsM::ALL_SHORTCODE, bool $debug = false): string
    {
        if (!is_file($filename)) {
            return '<p class="{{@pclass}}">Import file does not exist.</p>';
        }
        $lines = [];
        foreach ($this->GetLineFromFile($filename) as $line) {
            $lines[] = $line;
        }
        return $this->BulkSubscribeForm($lines, $priority, $list, $debug, false);
    }

    public function CreateImportHTMLform(): string
    {
        if (!Controller::allowed($this->fat, 'subscribers.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        return '<form action="{{@BaseURL}}import" method="post" enctype="multipart/form-data">' . Csrf::field($this->fat)
            . '<fieldset class="border rounded p-4"><legend>Import subscriber file</legend>'
            . '<div class="mb-3"><label class="form-label">List</label><select class="form-select" name="list_id">'
            . $this->CreateListHTMLDropDown(ListsM::ALL_SHORTCODE) . '</select></div>'
            . '<div class="mb-3"><label class="form-label">Priority</label><input class="form-control" type="number" name="s_priority" value="0"></div>'
            . '<div class="mb-3"><label class="form-label">File</label><input class="form-control" type="file" name="subscriber_file"></div>'
            . '<button class="btn btn-primary" type="submit">Import</button></fieldset></form>';
    }

    public function ImportUploadedFile(): string
    {
        Csrf::requireValid($this->fat);
        $file = $_FILES['subscriber_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '<p class="{{@pclass}}">No readable import file was uploaded.</p>';
        }
        return $this->BulkSubScribeFile(
            (string) $file['tmp_name'],
            (int) $this->fat->get('POST.s_priority'),
            (int) $this->fat->get('POST.list_id'),
            false
        );
    }

    public function CreateBulkUnsubscribeHTMLform(): string
    {
        if (!Controller::allowed($this->fat, 'subscribers.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        return '<form action="{{@BaseURL}}bulk-unsubscribe" method="post">' . Csrf::field($this->fat)
            . '<fieldset class="border rounded p-4"><legend>Unsubscribe multiple emails</legend>'
            . '<div class="mb-3"><label class="form-label">List</label><select class="form-select" name="list_id">'
            . $this->CreateListHTMLDropDown(ListsM::ALL_SHORTCODE) . '</select></div>'
            . '<div class="form-check"><input class="form-check-input" type="radio" name="scope" value="list" id="scope-list" checked><label class="form-check-label" for="scope-list">Remove from selected list only</label></div>'
            . '<div class="form-check"><input class="form-check-input" type="radio" name="scope" value="domain" id="scope-domain"><label class="form-check-label" for="scope-domain">Filter entire domains</label></div>'
            . '<div class="form-check"><input class="form-check-input" type="radio" name="scope" value="bounce" id="scope-bounce"><label class="form-check-label" for="scope-bounce">These are bounces</label></div>'
            . '<div class="form-check mb-3"><input class="form-check-input" type="radio" name="scope" value="spam" id="scope-spam"><label class="form-check-label" for="scope-spam">These are spam complainers</label></div>'
            . '<div class="mb-3"><label class="form-label">Reason</label><input class="form-control" name="reason" value="Bulk unsubscribe"></div>'
            . '<div class="mb-3"><label class="form-label">Email addresses</label><textarea class="form-control" rows="12" name="bemail"></textarea></div>'
            . '<button class="btn btn-danger" type="submit">Unsubscribe Emails</button></fieldset></form>';
    }

    public function BulkUnsubscribeForm(string $input, int|string $list = ListsM::ALL_SHORTCODE, string $reason = 'Bulk unsubscribe', string $scope = 'list'): string
    {
        Csrf::requireValid($this->fat);
        $listId = $this->resolveListId($list);
        if ($scope === 'list' && $listId < 1) {
            return '<p class="{{@pclass}}">The selected list does not exist.</p>';
        }
        $count = 0;
        foreach ($this->find_email_addresses($input) as $email) {
            $domain = $this->subscriber->getEmailDomain($email);
            if ($scope === 'domain') {
                if ($this->gdu->save($domain, 'SPAM')) {
                    $count++;
                }
                continue;
            }
            if ($scope === 'bounce' || $scope === 'spam') {
                $type = $scope === 'bounce' ? 'BOUNCE-ADMIN' : 'SPAM-ADMIN';
                if ($this->gu->save($email, $type, $reason)) {
                    $count++;
                }
                continue;
            }
            if ($this->subscriber->loadByEmail($email) && $this->lists->unsubscribe((int) $this->subscriber->s_id, $listId, $reason)) {
                $count++;
            }
        }
        return '<p class="{{@pclass}}">Number unsubscribed: ' . $count . '</p>';
    }

    /** Restore cross-installation synchronisation where dbservers are configured. */
    public function SyncSubscribers(): int
    {
        set_time_limit(86400);
        $servers = $this->fat->get('dbservers');
        if (!is_array($servers)) {
            return 0;
        }
        $totalAdded = 0;
        foreach ($servers as $server) {
            if (!is_array($server) || (int) ($server['active'] ?? 0) !== 1 || (string) ($server['domain'] ?? '') === $this->Domain) {
                continue;
            }
            $driver = strtolower((string) ($server['driver'] ?? 'pgsql'));
            $port = (int) ($server['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
            if ($driver === 'pgsql') {
                $dsn = 'pgsql:host=' . $server['host'] . ';port=' . $port . ';dbname=' . $server['name'] . ';sslmode=' . ($server['sslmode'] ?? 'prefer');
            } elseif ($driver === 'mysql') {
                $dsn = 'mysql:host=' . $server['host'] . ';port=' . $port . ';dbname=' . $server['name'] . ';charset=' . ($server['charset'] ?? 'utf8mb4');
            } else {
                continue;
            }
            $external = new \DB\SQL($dsn, (string) $server['user'], (string) $server['pass']);
            $rows = $external->exec(
                'SELECT s_email, s_priority, s_last_interacted FROM subscribers WHERE s_last_interacted IS NOT NULL ORDER BY s_last_interacted DESC, s_priority DESC, s_email ASC LIMIT 5000000'
            );
            $list = (string) ($server['list_shortcode'] ?? ListsM::ALL_SHORTCODE);
            foreach ($rows as $row) {
                $priority = (int) $row['s_priority'] + 1000000;
                if ($this->SimpleSubscribe((string) $row['s_email'], $priority, $list)) {
                    $totalAdded++;
                }
            }
        }
        return $totalAdded;
    }

    /** Restore the old integration entry point without granting consent. */
    public function EcwidSubscribe(string $email, int|string $list = ListsM::ALL_SHORTCODE): bool
    {
        return $this->SimpleSubscribe($email, 0, $list);
    }

    private function sendProfileNotification(string $token, string $muid, string $type): void
    {
        if ($this->mailer === null || !$this->RetrieveSubscriber($token) || !$this->mailer->OpenSMTP()) {
            return;
        }
        $email = (string) $this->subscriber->s_email;
        $name = trim((string) $this->subscriber->s_fname . ' ' . (string) $this->subscriber->s_lname);
        $profileUrl = rtrim($this->BaseURL, '/') . '/subscribe/' . rawurlencode($token) . ($muid !== '' ? '/' . rawurlencode($muid) : '');
        $subject = $email . ' has updated their profile on ' . $this->ListName;
        $html = '<p>Subscriber information for ' . htmlspecialchars($email) . ' on ' . htmlspecialchars($this->ListName) . ' has been updated.</p>'
            . '<p><a href="' . htmlspecialchars($profileUrl) . '">View your profile</a></p>';
        $text = "Subscriber information for {$email} on {$this->ListName} has been updated.\n{$profileUrl}\n";
        try {
            $this->mailer->SendNotification($muid, $type, $this->FromAddress, $email, $name, $subject, $html, $text, $token, '');
        } finally {
            $this->mailer->CloseSMTP();
        }
    }

    /** @param list<string> $lines */
    private function appendImportLog(string $filename, array $lines, string $footer): void
    {
        $logs = rtrim((string) $this->fat->get('LOGS'), DIRECTORY_SEPARATOR);
        if (!is_dir($logs)) {
            mkdir($logs, 0770, true);
        }
        $handle = fopen($logs . DIRECTORY_SEPARATOR . basename($filename), 'ab');
        if ($handle === false) {
            return;
        }
        fwrite($handle, "--------------\n" . date('Y-m-d H:i:s') . "\n");
        foreach ($lines as $line) {
            fwrite($handle, $line . "\n");
        }
        fwrite($handle, $footer . "\n");
        fclose($handle);
    }

    private function resolveListId(int|string $list): int
    {
        if (is_int($list) || ctype_digit((string) $list)) {
            return (int) $list;
        }
        $found = $this->lists->findByShortcode((string) $list) ?? $this->lists->findByName((string) $list);
        return (int) ($found['l_id'] ?? 0);
    }

}
