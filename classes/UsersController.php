<?php

declare(strict_types=1);

/**
 * Passwordless authentication and subscriber account controller.
 *
 * The historical users table has been removed. Subscribers are the canonical
 * identity records. A subscriber row does not imply mailing-list consent.
 */
class UsersController extends Controller
{
    protected Base $fat;
    protected \DB\SQL $dbPDO;
    protected string $BaseURL;
    protected string $ListName;
    protected string $FromAddress;
    protected string $AdminEmail;
    protected string $AdminName;

    protected AuthLoginTokenM $loginToken;
    protected AuthSessionM $authSession;
    protected AclService $acl;
    protected ListService $lists;
    protected int $magicLinkTtl;
    protected int $sessionTtl;
    protected string $sessionCookieName;

    public SubscribersM $user;
    public bool $uloggedin = false;
    public int $uadmin = 0;
    public int $uid = 0;
    public ?mailer $mailer = null;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->ListName = (string) $fat->get('ListName');
        $this->FromAddress = (string) $fat->get('FromAddress');
        $this->AdminEmail = (string) $fat->get('AdminEmail');
        $this->AdminName = (string) $fat->get('AdminName');

        $this->magicLinkTtl = $this->envInt('AUTH_MAGIC_LINK_TTL', 1800);
        $this->sessionTtl = $this->envInt('AUTH_SESSION_TTL', 86400);
        $this->sessionCookieName = $this->envString('AUTH_SESSION_COOKIE', 'ctnlist_session');

        $this->user = new SubscribersM($fat);
        $this->loginToken = new AuthLoginTokenM($fat);
        $this->authSession = new AuthSessionM($fat);
        $this->acl = new AclService($this->dbPDO);
        $this->lists = new ListService($fat, $this->dbPDO);

        $this->clearUserContext();
        $this->check_auth_cookies();
    }

    public function SetMailer(mailer $mailer): void
    {
        $this->mailer = $mailer;
    }

    public function CreateLoginHTMLform(
        string $returnAction = 'profile',
        ?int $returnMessageId = null,
        ?int $returnListId = null
    ): string {
        $csrf = Csrf::field($this->fat);
        $safeAction = htmlspecialchars($returnAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $messageField = $returnMessageId === null
            ? ''
            : '<input type="hidden" name="return_message_id" value="' . $returnMessageId . '">';
        $listField = $returnListId === null
            ? ''
            : '<input type="hidden" name="return_list_id" value="' . $returnListId . '">';

        return <<<HTML
<form name="loginform" action="{{@BaseURL}}login" method="post" role="form" class="mx-auto" style="max-width: 520px;">
  {$csrf}
  <input type="hidden" name="return_action" value="{$safeAction}">
  {$messageField}
  {$listField}
  <fieldset class="border rounded p-4">
    <legend>Sign in by email</legend>
    <p>Enter your email address. We will send you a one-time sign-in link valid for 30 minutes.</p>
    <div class="mb-3">
      <label class="form-label" for="login-email">Email address</label>
      <input class="form-control" id="login-email" name="email" type="email" maxlength="254" autocomplete="email" required>
    </div>
    <button class="btn btn-primary" type="submit">Email me a sign-in link</button>
  </fieldset>
</form>
HTML;
    }

    public function authenticationPrompt(
        string $subscriberToken,
        string $action,
        ?string $messageUid = null,
        ?int $listId = null
    ): string {
        $subscriber = new SubscribersM($this->fat);
        if (!$subscriber->read($subscriberToken)) {
            $this->fat->error(404);
        }

        $messageId = null;
        if ($messageUid !== null && $messageUid !== '') {
            // Use the established Mapper model for ordinary message lookup.
            $message = new MessagesM($this->fat);
            if ($message->read($messageUid)) {
                $messageId = (int) $message->m_id;
            }
        }

        $csrf = Csrf::field($this->fat);
        $masked = htmlspecialchars(
            SubscribersM::maskEmail((string) $subscriber->s_email),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $token = htmlspecialchars($subscriberToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeAction = htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $midField = $messageId === null ? '' : '<input type="hidden" name="message_id" value="' . $messageId . '">';
        $listField = $listId === null ? '' : '<input type="hidden" name="list_id" value="' . $listId . '">';

        return <<<HTML
<div class="card mx-auto" style="max-width: 620px;">
  <div class="card-body">
    <h1 class="h4">Authentication required</h1>
    <p>This request relates to <strong>{$masked}</strong>.</p>
    <p>Before continuing, verify that you can access this email account. The secure sign-in link will return you directly to this action.</p>
    <form action="{{@BaseURL}}auth/request" method="post">
      {$csrf}
      <input type="hidden" name="subscriber_token" value="{$token}">
      <input type="hidden" name="return_action" value="{$safeAction}">
      {$midField}
      {$listField}
      <button class="btn btn-primary" type="submit">Send secure sign-in link</button>
    </form>
  </div>
</div>
HTML;
    }

    public function requestMagicLink(
        string $email,
        string $returnAction = 'profile',
        ?int $returnMessageId = null,
        ?int $returnListId = null
    ): bool {
        $email = SubscribersM::normaliseEmail($email);
        if (!SubscribersM::validEmail($email) || $this->mailer === null) {
            return false;
        }

        $created = false;
        if (!$this->user->loadByEmail($email)) {
            if (!$this->user->createIdentity($email)) {
                return false;
            }
            $created = true;
        }

        $subscriberId = (int) $this->user->s_id;
        if ($subscriberId < 1 || $this->magicLinkRateLimited($email)) {
            return false;
        }

        if ($created && $returnAction === 'profile') {
            $returnAction = 'confirm';
            $returnListId = $this->lists->allListId();
        }

        if (!$this->validReturnAction($returnAction)) {
            $returnAction = 'profile';
            $returnMessageId = null;
            $returnListId = null;
        }

        $rawToken = $this->randomToken();
        $now = time();

        try {
            $this->loginToken->reset();
            $this->loginToken->alt_s_id = $subscriberId;
            $this->loginToken->alt_email = $email;
            $this->loginToken->alt_token_hash = hash('sha256', $rawToken);
            $this->loginToken->alt_created_at = date('Y-m-d H:i:s', $now);
            $this->loginToken->alt_expires_at = date('Y-m-d H:i:s', $now + $this->magicLinkTtl);
            $this->loginToken->alt_used_at = null;
            $this->loginToken->alt_requested_ip = $this->clientIp();
            $this->loginToken->alt_user_agent = $this->userAgent();
            $this->loginToken->alt_return_action = $returnAction;
            $this->loginToken->alt_return_m_id = $returnMessageId;
            $this->loginToken->alt_return_l_id = $returnListId;
            $this->loginToken->save();

            $loginUrl = rtrim($this->BaseURL, '/') . '/auth/verify?token=' . rawurlencode($rawToken);
            if (!$this->mailer->OpenSMTP()) {
                $this->loginToken->erase();
                return false;
            }
            try {
                $sent = $this->mailer->SendMagicLink(
                    $email,
                    (string) $this->user->s_uuid,
                    $loginUrl,
                    $this->magicLinkTtl
                );
            } finally {
                $this->mailer->CloseSMTP();
            }
            if ($sent < 1) {
                $this->loginToken->erase();
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('ctnlist auth: failed to request magic link: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{success:bool,new:bool,redirect:string} */
    public function verifyMagicLink(string $rawToken): array
    {
        $failure = ['success' => false, 'new' => false, 'redirect' => '/login'];
        $rawToken = trim($rawToken);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $rawToken)) {
            return $failure;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $this->loginToken->load([
                'alt_token_hash = :hash AND alt_used_at IS NULL AND alt_expires_at > :now',
                ':hash' => hash('sha256', $rawToken),
                ':now' => $now,
            ]);
            if ($this->loginToken->dry()) {
                return $failure;
            }

            $subscriberId = (int) $this->loginToken->alt_s_id;
            $this->user->load(['s_id = :sid', ':sid' => $subscriberId]);
            if ($this->user->dry()) {
                return $failure;
            }

            $isNew = empty($this->user->s_last_login_at);
            $this->user->s_last_login_at = $now;
            $this->user->s_last_login_ip = $this->clientIp();
            $this->user->save();

            $this->acl->bootstrapInitialAdministrator($subscriberId, (string) $this->user->s_email);

            $this->loginToken->alt_used_at = $now;
            $this->loginToken->save();

            $sessionToken = $this->randomToken();
            $this->authSession->reset();
            $this->authSession->as_s_id = $subscriberId;
            $this->authSession->as_token_hash = hash('sha256', $sessionToken);
            $this->authSession->as_created_at = $now;
            $this->authSession->as_expires_at = date('Y-m-d H:i:s', time() + $this->sessionTtl);
            $this->authSession->as_last_seen_at = $now;
            $this->authSession->as_revoked_at = null;
            $this->authSession->as_ip_address = $this->clientIp();
            $this->authSession->as_user_agent = $this->userAgent();
            $this->authSession->save();

            $redirect = $this->returnPath(
                (string) $this->loginToken->alt_return_action,
                (int) $this->loginToken->alt_return_m_id,
                (int) $this->loginToken->alt_return_l_id,
                (string) $this->user->s_uuid
            );

            $this->setAuthCookie($sessionToken);
            $this->setUserContext();
            return ['success' => true, 'new' => $isNew, 'redirect' => $redirect];
        } catch (\Throwable $e) {
            error_log('ctnlist auth: magic-link verification failed: ' . $e->getMessage());
            return $failure;
        }
    }

    public function check_auth_cookies(): bool
    {
        $raw = trim((string) $this->fat->get('COOKIE.' . $this->sessionCookieName));
        if ($raw === '') {
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $raw)) {
            $this->clearAuthCookie();
            return false;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $this->authSession->load([
                'as_token_hash = :hash AND as_revoked_at IS NULL AND as_expires_at > :now',
                ':hash' => hash('sha256', $raw),
                ':now' => $now,
            ]);
            if ($this->authSession->dry()) {
                $this->clearAuthCookie();
                return false;
            }

            $this->user->load(['s_id = :sid', ':sid' => (int) $this->authSession->as_s_id]);
            if ($this->user->dry()) {
                $this->authSession->as_revoked_at = $now;
                $this->authSession->save();
                $this->clearAuthCookie();
                return false;
            }

            $lastSeen = strtotime((string) $this->authSession->as_last_seen_at) ?: 0;
            if ($lastSeen < time() - 300) {
                $this->authSession->as_last_seen_at = $now;
                $this->authSession->save();
            }
            $this->setUserContext();
            return true;
        } catch (\Throwable $e) {
            error_log('ctnlist auth: session lookup failed: ' . $e->getMessage());
            $this->clearAuthCookie();
            return false;
        }
    }

    public function logout(): void
    {
        $raw = trim((string) $this->fat->get('COOKIE.' . $this->sessionCookieName));
        if ($raw !== '') {
            $this->authSession->load([
                'as_token_hash = :hash AND as_revoked_at IS NULL',
                ':hash' => hash('sha256', $raw),
            ]);
            if (!$this->authSession->dry()) {
                $this->authSession->as_revoked_at = date('Y-m-d H:i:s');
                $this->authSession->save();
            }
        }
        $this->clearAuthCookie();
        $this->clearLegacyAuthCookies();
        $this->clearUserContext();
        $this->user->reset();
    }

    public function matchesSubscriberToken(string $token): bool
    {
        return $this->uloggedin
            && $token !== ''
            && hash_equals((string) $this->user->s_uuid, strtolower(trim($token)));
    }

    public function requireMatchingSubscriberToken(string $token): void
    {
        if (!$this->matchesSubscriberToken($token)) {
            $this->fat->error(403);
        }
    }

    public function can(string $permission): bool
    {
        return $this->uloggedin && $this->acl->can($this->uid, $permission);
    }

    public function CreateEditProfileHTMLform(): string
    {
        if (!$this->uloggedin) {
            return '<p class="{{@pclass}}">Please login to edit your profile.</p>';
        }

        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = Csrf::field($this->fat);
        $gender = (new formfield())->CreateGenderHTMLDropDown((string) $this->user->s_gender);
        $province = (new formfield())->CreateProvinceHTMLDropDown((string) $this->user->s_province);
        $country = (new formfield())->CreateCountryHTMLDropDown((string) $this->user->s_country);

        return <<<HTML
<form action="{{@BaseURL}}edit-profile" method="post">
  {$csrf}
  <fieldset class="border rounded p-4">
    <legend>Edit profile</legend>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">First name</label><input class="form-control" name="s_fname" value="{$e($this->user->s_fname)}"></div>
      <div class="col-md-6"><label class="form-label">Last name</label><input class="form-control" name="s_lname" value="{$e($this->user->s_lname)}"></div>
      <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" value="{$e($this->user->s_email)}" readonly></div>
      <div class="col-md-6"><label class="form-label">Cell</label><input class="form-control" name="s_phone" value="{$e($this->user->s_phone)}"></div>
      <div class="col-md-6"><label class="form-label">Birthdate</label><input class="form-control" type="date" name="s_birthday" value="{$e($this->user->s_birthday)}"></div>
      <div class="col-md-6"><label class="form-label">Gender</label><select class="form-select" name="s_gender">{$gender}</select></div>
      <div class="col-md-6"><label class="form-label">Province</label><select class="form-select" name="s_province">{$province}</select></div>
      <div class="col-md-6"><label class="form-label">Country</label><select class="form-select" name="s_country">{$country}</select></div>
      <div class="col-md-6"><label class="form-label">Company</label><input class="form-control" name="s_business" value="{$e($this->user->s_business)}"></div>
      <div class="col-md-6"><label class="form-label">Website</label><input class="form-control" name="s_url" value="{$e($this->user->s_url)}"></div>
      <div class="col-12"><label class="form-label">Photo URL</label><input class="form-control" name="s_photo" value="{$e($this->user->s_photo)}"></div>
      <div class="col-12"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </div>
  </fieldset>
</form>
HTML;
    }

    public function save(): string
    {
        if (!$this->uloggedin) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        Csrf::requireValid($this->fat);

        $this->user->s_fname = mb_substr(trim((string) $this->fat->get('POST.s_fname')), 0, 100);
        $this->user->s_lname = mb_substr(trim((string) $this->fat->get('POST.s_lname')), 0, 100);
        $this->user->s_photo = mb_substr(trim((string) $this->fat->get('POST.s_photo')), 0, 253);
        $this->user->s_gender = mb_substr(trim((string) $this->fat->get('POST.s_gender')), 0, 30);
        $birthday = trim((string) $this->fat->get('POST.s_birthday'));
        $this->user->s_birthday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) ? $birthday : null;
        $this->user->s_business = mb_substr(trim((string) $this->fat->get('POST.s_business')), 0, 100);
        $this->user->s_province = mb_substr(trim((string) $this->fat->get('POST.s_province')), 0, 100);
        $this->user->s_country = mb_substr(trim((string) $this->fat->get('POST.s_country')), 0, 100);
        $this->user->s_phone = mb_substr(trim((string) $this->fat->get('POST.s_phone')), 0, 30);
        $this->user->s_url = mb_substr(trim((string) $this->fat->get('POST.s_url')), 0, 253);
        $this->user->save();
        $this->setUserContext();

        // Preserve the established ctnlist behaviour: every subscriber-initiated
        // profile change generates a transactional notification and Send Log row.
        $html = '<p class="{{@pclass}}">Your profile has been updated.</p>';
        if ($this->mailer !== null && $this->mailer->OpenSMTP()) {
            $name = trim((string) $this->user->s_fname . ' ' . (string) $this->user->s_lname);
            $subject = $this->ListName . ' notification: ' . (string) $this->user->s_email
                . ' has updated their user profile';
            $notificationHtml = '<p>Profile for subscriber ' . htmlspecialchars(
                (string) $this->user->s_uuid,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . ' on ' . htmlspecialchars($this->ListName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ' has been updated.</p>';
            $notificationText = 'Profile for subscriber ' . (string) $this->user->s_uuid
                . ' on ' . $this->ListName . " has been updated.\n";
            try {
                $this->mailer->SendNotification(
                    '',
                    'UPDATE-USER',
                    $this->FromAddress,
                    (string) $this->user->s_email,
                    $name,
                    $subject,
                    $notificationHtml,
                    $notificationText,
                    (string) $this->user->s_uuid,
                    ''
                );
            } finally {
                $this->mailer->CloseSMTP();
            }
        }
        return $html;
    }

    public function DisplayProfileHTML(): string
    {
        if (!$this->uloggedin) {
            return '<p class="{{@pclass}}">Please login to view your profile.</p>';
        }

        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="card mb-4"><div class="card-body">';
        $html .= '<h1 class="h4">Subscriber profile</h1>';
        $html .= '<dl class="row mb-0">';
        $html .= '<dt class="col-sm-3">Name</dt><dd class="col-sm-9">' . $e(trim((string) $this->user->s_fname . ' ' . (string) $this->user->s_lname)) . '</dd>';
        $html .= '<dt class="col-sm-3">Email</dt><dd class="col-sm-9">' . $e($this->user->s_email) . '</dd>';
        $html .= '<dt class="col-sm-3">Subscriber UUIDv7</dt><dd class="col-sm-9"><code>' . $e($this->user->s_uuid) . '</code></dd>';
        $html .= '<dt class="col-sm-3">Record created</dt><dd class="col-sm-9">' . $e($this->user->s_created_at) . '</dd>';
        $html .= '<dt class="col-sm-3">Company</dt><dd class="col-sm-9">' . $e($this->user->s_business) . '</dd>';
        $html .= '</dl><a class="btn btn-outline-primary" href="{{@BaseURL}}edit-profile">Edit profile</a>';
        $html .= '</div></div>';

        $html .= '<h2 class="h4">List memberships</h2><div class="table-responsive"><table class="table table-striped">';
        $html .= '<thead><tr><th>List</th><th>Membership provenance</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($this->lists->memberships($this->uid) as $membership) {
            $confirmed = filter_var($membership['ls_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $unsubscribed = filter_var($membership['ls_unsubscribed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $status = $confirmed && !$unsubscribed ? 'Confirmed' : ($unsubscribed ? 'Unsubscribed' : 'Awaiting confirmation');
            $shortcode = rawurlencode((string) $membership['l_shortcode']);
            $action = $confirmed && !$unsubscribed
                ? '<a class="btn btn-sm btn-outline-danger" href="{{@BaseURL}}unsubscribe/' . $e($this->user->s_uuid) . '/' . $shortcode . '">Unsubscribe</a>'
                : '<a class="btn btn-sm btn-outline-success" href="{{@BaseURL}}confirm/' . $e($this->user->s_uuid) . '/' . $shortcode . '">Confirm</a>';
            $membershipUuid = (string) ($membership['ls_uuid'] ?? '');
            $membershipSince = (string) ($membership['ls_subscribed_at'] ?? '');
            $provenance = $membershipUuid === ''
                ? 'Not joined'
                : '<code>' . $e($membershipUuid) . '</code><br><small>Joined ' . $e($membershipSince) . '</small>';
            $html .= '<tr><td>' . $e($membership['l_name']) . '</td><td>' . $provenance . '</td><td>' . $e($status) . '</td><td>' . $action . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<p><a class="btn btn-primary" href="{{@BaseURL}}my/messages">Messages sent to me</a></p>';
        return $html;
    }

    public function DisplayMessageHistoryHTML(): string
    {
        if (!$this->uloggedin) {
            return '<p class="{{@pclass}}">Please login to view your messages.</p>';
        }
        // Keep SQL out of the controller. The Mapper model owns the one
        // subscriber/message history query required by this joined report.
        $activity = new SmlogM($this->fat);
        $rows = $activity->messageHistory((string) $this->user->s_uuid);
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($rows === []) {
            return '<p class="{{@pclass}}">No messages have been recorded as sent to this account.</p>';
        }
        $html = '<div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Subject</th><th>Sent</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $muid = rawurlencode((string) $row['m_uniqid']);
            $token = rawurlencode((string) $this->user->s_uuid);
            $html .= '<tr><td>' . $e($row['m_subject']) . '</td><td>' . $e($row['sml_date_sent']) . '</td><td class="d-flex flex-wrap gap-1">';
            $html .= '<a class="btn btn-sm btn-outline-primary" href="{{@BaseURL}}resend/' . $token . '/' . $muid . '">Send again</a>';
            $html .= '<a class="btn btn-sm btn-outline-secondary" href="{{@BaseURL}}forward/' . $token . '/' . $muid . '">Forward</a>';
            $html .= '<a class="btn btn-sm btn-outline-success" href="{{@BaseURL}}like/' . $token . '/' . $muid . '">Like</a>';
            $html .= '<a class="btn btn-sm btn-outline-danger" href="{{@BaseURL}}dislike/' . $token . '/' . $muid . '">Dislike</a>';
            $html .= '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function magicLinkRateLimited(string $email): bool
    {
        $emailLimit = max(1, $this->envInt('AUTH_MAGIC_LINK_MAX_PER_EMAIL', 5));
        $ipLimit = max(1, $this->envInt('AUTH_MAGIC_LINK_MAX_PER_IP', 20));
        $emailWindow = max(60, $this->envInt('AUTH_MAGIC_LINK_EMAIL_WINDOW', 900));
        $ipWindow = max(60, $this->envInt('AUTH_MAGIC_LINK_IP_WINDOW', 3600));
        $ip = $this->clientIp();

        $emailCount = $this->loginToken->count([
            'alt_email = :email AND alt_created_at >= :since',
            ':email' => $email,
            ':since' => date('Y-m-d H:i:s', time() - $emailWindow),
        ]);
        if ((int) $emailCount >= $emailLimit) {
            return true;
        }
        if ($ip === '') {
            return false;
        }
        $ipCount = $this->loginToken->count([
            'alt_requested_ip = :ip AND alt_created_at >= :since',
            ':ip' => $ip,
            ':since' => date('Y-m-d H:i:s', time() - $ipWindow),
        ]);
        return (int) $ipCount >= $ipLimit;
    }

    private function returnPath(string $action, int $messageId, int $listId, string $subscriberToken): string
    {
        $token = rawurlencode($subscriberToken);
        $muid = '';
        if ($messageId > 0) {
            // Use the Mapper so authentication return routing follows the
            // same data-access pattern as the rest of the message model.
            $message = new MessagesM($this->fat);
            if ($message->loadByMid($messageId)) {
                $muid = rawurlencode((string) $message->m_uniqid);
            }
        }
        $shortcode = '';
        if ($listId > 0) {
            $list = $this->lists->findById($listId);
            $shortcode = rawurlencode((string) ($list['l_shortcode'] ?? ''));
        }

        return match ($action) {
            'confirm' => $shortcode !== ''
                ? "/confirm/{$token}/{$shortcode}" . ($muid !== '' ? "/{$muid}" : '')
                : "/profile/subscriber/{$token}",
            'unsubscribe' => $shortcode !== ''
                ? "/unsubscribe/{$token}/{$shortcode}" . ($muid !== '' ? "/{$muid}" : '')
                : "/profile/subscriber/{$token}",
            'forward' => $muid !== '' ? "/forward/{$token}/{$muid}" : "/profile/subscriber/{$token}",
            'like' => $muid !== '' ? "/like/{$token}/{$muid}" : "/profile/subscriber/{$token}",
            'dislike' => $muid !== '' ? "/dislike/{$token}/{$muid}" : "/profile/subscriber/{$token}",
            'resend' => $muid !== '' ? "/resend/{$token}/{$muid}" : "/profile/subscriber/{$token}",
            'messages' => '/my/messages',
            default => "/profile/subscriber/{$token}",
        };
    }

    private function validReturnAction(string $action): bool
    {
        return in_array($action, ['profile', 'messages', 'confirm', 'unsubscribe', 'forward', 'like', 'dislike', 'resend'], true);
    }

    private function setUserContext(): void
    {
        $this->uloggedin = true;
        $this->uid = (int) $this->user->s_id;
        $this->uadmin = $this->acl->isAdministrator($this->uid) ? 1 : 0;
        $firstName = trim((string) $this->user->s_fname);
        $lastName = trim((string) $this->user->s_lname);
        $email = trim((string) $this->user->s_email);
        $token = trim((string) $this->user->s_uuid);

        $this->fat->set('uloggedin', true);
        $this->fat->set('uadmin', $this->uadmin);
        $this->fat->set('uid', $this->uid);
        $this->fat->set('ufname', $firstName);
        $this->fat->set('ulname', $lastName);
        $this->fat->set('uname', trim($firstName . ' ' . $lastName));
        $this->fat->set('SESSION.email', $email);
        $this->fat->set('SESSION.suid', $token);
        $this->fat->set('SESSION.subscriber_id', $this->uid);
        $this->fat->set('Email', $email);
        $this->fat->set('acl_permissions', $this->acl->permissions($this->uid));
    }

    private function clearUserContext(): void
    {
        $this->uloggedin = false;
        $this->uadmin = 0;
        $this->uid = 0;
        $this->fat->set('uloggedin', false);
        $this->fat->set('uadmin', 0);
        $this->fat->set('uid', 0);
        $this->fat->set('ufname', '');
        $this->fat->set('ulname', '');
        $this->fat->set('uname', '');
        $this->fat->set('SESSION.email', '');
        $this->fat->set('SESSION.suid', '');
        $this->fat->set('SESSION.subscriber_id', 0);
        $this->fat->set('Email', '');
        $this->fat->set('acl_permissions', []);
    }

    private function setAuthCookie(string $rawToken): void
    {
        setcookie($this->sessionCookieName, $rawToken, [
            'expires' => time() + $this->sessionTtl,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearAuthCookie(): void
    {
        setcookie($this->sessionCookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Remove cookies used by the pre-passwordless authentication code. */
    private function clearLegacyAuthCookies(): void
    {
        foreach (['identifier', 'session_token'] as $name) {
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $this->isSecureRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function clientIp(): string
    {
        return mb_substr(trim((string) $this->fat->get('IP')), 0, 45);
    }

    private function userAgent(): string
    {
        return mb_substr(trim((string) $this->fat->get('AGENT')), 0, 500);
    }

    private function isSecureRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function envString(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function envInt(string $name, int $default): int
    {
        $value = $this->envString($name, (string) $default);
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }
}