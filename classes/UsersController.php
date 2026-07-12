<?php

/**
 * User profile and passwordless authentication controller.
 *
 * Janrain/RPX authentication has been removed. Login now uses a one-time
 * email link and revocable server-side sessions.
 */
class UsersController extends Controller
{
    protected $fat;
    protected $dbPDO;
    protected $BaseURL;
    protected $ListName;
    protected $FromAddress;
    protected $AdminEmail;
    protected $AdminName;

    protected ?AuthLoginTokenM $loginToken = null;
    protected ?AuthSessionM $authSession = null;
    protected int $magicLinkTtl;
    protected int $sessionTtl;
    protected string $sessionCookieName;

    public $user;
    public $uloggedin;
    public $uadmin;
    public $uid;
    public $mailer = null;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = $fat->get('BaseURL');
        $this->ListName = $fat->get('ListName');
        $this->FromAddress = $fat->get('FromAddress');
        $this->AdminEmail = $fat->get('AdminEmail');
        $this->AdminName = $fat->get('AdminName');

        $this->magicLinkTtl = $this->envInt('AUTH_MAGIC_LINK_TTL', 900);
        $this->sessionTtl = $this->envInt('AUTH_SESSION_TTL', 2419200);
        $this->sessionCookieName = $this->envString('AUTH_SESSION_COOKIE', 'ctnlist_session');

        $this->user = new UsersM($fat);
        $this->clearUserContext();

        // The application must continue to render before the auth migration is
        // run. Auth operations themselves remain unavailable until the tables
        // exist, and the failure is logged rather than exposed to the visitor.
        $this->initialiseAuthMappers();
        $this->check_auth_cookies();
    }

    public function SetMailer(mailer $mailer): void
    {
        $this->mailer = $mailer;
    }

    public function CreateLoginHTMLform(): string
    {
        return <<<HTML
<form name="loginform" action="{{@BaseURL}}login" method="post" role="form" class="mx-auto" style="max-width: 520px;">
  <fieldset class="border rounded p-4">
    <legend>Sign in by email</legend>
    <p>Enter your email address. We will send you a one-time sign-in link.</p>
    <div class="mb-3">
      <label class="form-label" for="login-email">Email address</label>
      <input class="form-control" id="login-email" name="email" type="email" maxlength="254" autocomplete="email" required>
    </div>
    <button class="btn btn-primary" type="submit">Email me a sign-in link</button>
  </fieldset>
</form>
HTML;
    }

    /**
     * Request a one-time magic link.
     *
     * The route must always show the same generic response, regardless of the
     * return value, to avoid leaking whether an address already has an account.
     */
    public function requestMagicLink(string $email): bool
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        if (!$this->initialiseAuthMappers() || $this->mailer === null) {
            return false;
        }

        if ($this->magicLinkRateLimited($email)) {
            error_log('ctnlist auth: magic-link rate limit reached');
            return false;
        }

        $rawToken = $this->randomToken();
        $now = time();

        try {
            $this->loginToken->reset();
            $this->loginToken->email = $email;
            $this->loginToken->token_hash = hash('sha256', $rawToken);
            $this->loginToken->created_at = date('Y-m-d H:i:s', $now);
            $this->loginToken->expires_at = date('Y-m-d H:i:s', $now + $this->magicLinkTtl);
            $this->loginToken->used_at = null;
            $this->loginToken->requested_ip = $this->clientIp();
            $this->loginToken->user_agent = $this->userAgent();
            $this->loginToken->save();

            $loginUrl = rtrim((string) $this->BaseURL, '/')
                . '/auth/verify?token=' . rawurlencode($rawToken);

            if (!$this->mailer->OpenSMTP()) {
                $this->loginToken->erase();
                return false;
            }

            try {
                $sent = $this->mailer->SendMagicLink($email, $loginUrl, $this->magicLinkTtl);
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

    /**
     * Verify a one-time token and create a secure revocable session.
     *
     * Returns 1 for a new user, 2 for an existing user and 0 for failure.
     */
    public function verifyMagicLink(string $rawToken): int
    {
        $rawToken = trim($rawToken);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $rawToken)) {
            return 0;
        }

        if (!$this->initialiseAuthMappers()) {
            return 0;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $tokenHash = hash('sha256', $rawToken);

            $this->loginToken->load([
                'token_hash = :hash AND used_at IS NULL AND expires_at > :now',
                ':hash' => $tokenHash,
                ':now' => $now,
            ]);

            if ($this->loginToken->dry()) {
                return 0;
            }

            $email = strtolower(trim((string) $this->loginToken->email));
            $this->user->load(['LOWER(u_email) = :email', ':email' => $email]);

            if ($this->user->dry()) {
                $loginStatus = 1;
                $this->user->reset();
                $this->user->u_uniqid = bin2hex(random_bytes(16));
                $this->user->u_identifier = 'email:' . hash('sha256', $email);
                $this->user->u_email = $email;
                $this->user->u_provider = 'passwordless-email';
                $this->user->u_admin = 0;
            } else {
                $loginStatus = 2;
            }

            $this->user->u_last_login = $now;
            $this->user->u_ip = $this->clientIp();
            $this->user->u_xfwdfor = '';
            $this->user->save();

            $userId = (int) ($this->user->u_id ?: $this->user->_id);
            if ($userId < 1) {
                throw new \RuntimeException('Authenticated user does not have a valid ID.');
            }

            // Mark the magic link as used before issuing a session so that a
            // subsequent request cannot reuse it successfully.
            $this->loginToken->used_at = $now;
            $this->loginToken->save();

            $sessionToken = $this->randomToken();
            $this->authSession->reset();
            $this->authSession->user_id = $userId;
            $this->authSession->token_hash = hash('sha256', $sessionToken);
            $this->authSession->created_at = $now;
            $this->authSession->expires_at = date('Y-m-d H:i:s', time() + $this->sessionTtl);
            $this->authSession->last_seen_at = $now;
            $this->authSession->revoked_at = null;
            $this->authSession->ip_address = $this->clientIp();
            $this->authSession->user_agent = $this->userAgent();
            $this->authSession->save();

            $this->setAuthCookie($sessionToken);
            $this->setUserContext();
            return $loginStatus;
        } catch (\Throwable $e) {
            error_log('ctnlist auth: magic-link verification failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function check_auth_cookies(): bool
    {
        $rawSessionToken = trim((string) $this->fat->get('COOKIE.' . $this->sessionCookieName));
        if ($rawSessionToken === '') {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $rawSessionToken) || !$this->initialiseAuthMappers()) {
            $this->clearAuthCookie();
            return false;
        }

        try {
            $now = date('Y-m-d H:i:s');
            $this->authSession->load([
                'token_hash = :hash AND revoked_at IS NULL AND expires_at > :now',
                ':hash' => hash('sha256', $rawSessionToken),
                ':now' => $now,
            ]);

            if ($this->authSession->dry()) {
                $this->clearAuthCookie();
                return false;
            }

            $this->user->load(['u_id = :uid', ':uid' => (int) $this->authSession->user_id]);
            if ($this->user->dry()) {
                $this->authSession->revoked_at = $now;
                $this->authSession->save();
                $this->clearAuthCookie();
                return false;
            }

            $lastSeen = strtotime((string) $this->authSession->last_seen_at) ?: 0;
            if ($lastSeen < time() - 300) {
                $this->authSession->last_seen_at = $now;
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
        $rawSessionToken = trim((string) $this->fat->get('COOKIE.' . $this->sessionCookieName));

        if ($rawSessionToken !== '' && $this->initialiseAuthMappers()) {
            try {
                $this->authSession->load([
                    'token_hash = :hash AND revoked_at IS NULL',
                    ':hash' => hash('sha256', $rawSessionToken),
                ]);
                if (!$this->authSession->dry()) {
                    $this->authSession->revoked_at = date('Y-m-d H:i:s');
                    $this->authSession->save();
                }
            } catch (\Throwable $e) {
                error_log('ctnlist auth: session revocation failed: ' . $e->getMessage());
            }
        }

        $this->clearAuthCookie();
        $this->clearLegacyAuthCookies();
        $this->clearUserContext();
        $this->user->reset();
    }

    private function initialiseAuthMappers(): bool
    {
        if ($this->loginToken !== null && $this->authSession !== null) {
            return true;
        }

        try {
            $this->loginToken = new AuthLoginTokenM($this->fat);
            $this->authSession = new AuthSessionM($this->fat);
            return true;
        } catch (\Throwable $e) {
            $this->loginToken = null;
            $this->authSession = null;
            error_log('ctnlist auth tables unavailable: ' . $e->getMessage());
            return false;
        }
    }

    private function magicLinkRateLimited(string $email): bool
    {
        $emailLimit = max(1, $this->envInt('AUTH_MAGIC_LINK_MAX_PER_EMAIL', 5));
        $ipLimit = max(1, $this->envInt('AUTH_MAGIC_LINK_MAX_PER_IP', 20));
        $emailSince = date('Y-m-d H:i:s', time() - 900);
        $ipSince = date('Y-m-d H:i:s', time() - 3600);
        $ip = $this->clientIp();

        $emailCount = $this->loginToken->count([
            'email = :email AND created_at >= :since',
            ':email' => $email,
            ':since' => $emailSince,
        ]);

        if ((int) $emailCount >= $emailLimit) {
            return true;
        }

        if ($ip === '') {
            return false;
        }

        $ipCount = $this->loginToken->count([
            'requested_ip = :ip AND created_at >= :since',
            ':ip' => $ip,
            ':since' => $ipSince,
        ]);

        return (int) $ipCount >= $ipLimit;
    }

    private function setUserContext(): void
    {
        $this->uloggedin = true;
        $this->uadmin = (int) $this->user->u_admin;
        $this->uid = (int) $this->user->u_id;

        $firstName = trim((string) $this->user->u_fname);
        $lastName = trim((string) $this->user->u_lname);
        $displayName = trim($firstName . ' ' . $lastName);
        $email = trim((string) $this->user->u_email);
        $suid = trim((string) $this->user->u_suid);
        if ($suid === '' && $email !== '') {
            $suid = md5($email);
        }

        $this->fat->set('uloggedin', true);
        $this->fat->set('uadmin', $this->uadmin);
        $this->fat->set('ufname', $firstName);
        $this->fat->set('ulname', $lastName);
        $this->fat->set('uname', $displayName);
        $this->fat->set('SESSION.email', $email);
        $this->fat->set('SESSION.suid', $suid);
        $this->fat->set('Email', $email);
    }

    private function clearUserContext(): void
    {
        $this->uloggedin = false;
        $this->uadmin = 0;
        $this->uid = 0;
        $this->fat->set('uloggedin', false);
        $this->fat->set('uadmin', 0);
        $this->fat->set('ufname', '');
        $this->fat->set('ulname', '');
        $this->fat->set('uname', '');
        $this->fat->set('SESSION.email', '');
        $this->fat->set('SESSION.suid', '');
        $this->fat->set('Email', '');
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
        $ip = trim((string) $this->fat->get('IP'));
        return substr($ip, 0, 45);
    }

    private function userAgent(): string
    {
        return substr(trim((string) $this->fat->get('AGENT')), 0, 500);
    }

    private function isSecureRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private function envString(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return trim($value);
    }

    private function envInt(string $name, int $default): int
    {
        $value = $this->envString($name, (string) $default);
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }

  public function CreateEditProfileHTMLform() {
    $html = "";
    if (!$this->uloggedin) {
      $html .= "<p class=\"{{@pclass}\">Please login to edit your profile.</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_FormOpen("profileform","{{@BaseURL}}edit-profile","POST");
    $html .= $ff->FF_FieldsetOpen("{{@fieldsetclass}}");
    $html .= $ff->FF_Legend("Edit Profile");

    // don't think this is needed anymore because email is not editable
    $html .= $ff->FF_hidden("u_oemail",$this->user->u_email);

    $html .= $ff->FF_DivOpen("{{@rowclass}}");

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_fname","text",$this->user->u_fname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("First Name","u_fname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_lname","text",$this->user->u_lname,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Last Name","u_lname","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_email","email",$this->user->u_email,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Email","u_email","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_phone","text",$this->user->u_phone,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Cell","u_phone","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_input("u_birthday","date",$this->user->u_birthday,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Birthdate","u_birthday","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_gender",$ff->CreateGenderHTMLDropDown($this->user->u_gender),"{{@selectclass}}");
    $html .= $ff->FF_Label("Gender","u_gender","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_province",$ff->CreateProvinceHTMLDropDown($this->user->u_province),"{{@selectclass}}");
    $html .= $ff->FF_Label("Province","u_province","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass3}}");
    $html .= $ff->FF_DropDown("u_country",$ff->CreateCountryHTMLDropDown($this->user->u_country),"{{@selectclass}}");
    $html .= $ff->FF_Label("Country","u_country","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("u_business","text",$this->user->u_business,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Company","u_business","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass6}}");
    $html .= $ff->FF_input("u_url","text",$this->user->u_url,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Web site address","u_url","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_input("u_photo","text",$this->user->u_photo,"","{{@inputclass}}");
    $html .= $ff->FF_Label("Photo URL","u_photo","{{@labelclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivOpen("{{@columnclass12}}");
    $html .= $ff->FF_Button("submit","Save Profile","{{@buttonclass}}");
    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_DivClose();

    $html .= $ff->FF_FieldsetClose();
    $html .= $ff->FF_FormClose();
    return $html;
  }

  public function save() {
    $html = "";
    $this->user->u_fname = trim($this->fat->get('POST.u_fname'));
    $this->user->u_lname = trim($this->fat->get('POST.u_lname'));
    $this->user->u_photo = trim($this->fat->get('POST.u_photo'));
    $this->user->u_gender = trim($this->fat->get('POST.u_gender'));
    $this->user->u_birthday = trim($this->fat->get('POST.u_birthday'));
    $this->user->u_business = trim($this->fat->get('POST.u_business'));
    $this->user->u_province = trim($this->fat->get('POST.u_province'));
    $this->user->u_country = trim($this->fat->get('POST.u_country'));
    $this->user->u_phone = trim($this->fat->get('POST.u_phone'));
    $this->user->u_email = trim($this->fat->get('POST.u_email'));
    $this->user->u_url = trim($this->fat->get('POST.u_url'));

    $u_oemail = trim($this->fat->get('POST.u_oemail'));
    // $u_bemail = trim($this->fat->get('POST.u_bemail'));

    $this->user->save();

    // $subscriber = $this->fat->get('subscribers');
    // Claims the primary email
    // $content .= $subscriber->ClaimEmail($this->user->u_email,$user->uid);
    // Claims the secondary emails
    // $content .= $subscriber->ClaimEmail($u_bemail,$user->uid);

    $html .= "<p class=\"{{@pclass}\">{$u_oemail} --> {$this->user->u_email} profile info updated.</p>";
    // send profile update notification email to user and admin

    $mtype = "UPDATE-USER";
    $muid = '';
    $mfrom = $this->FromAddress;
    $toemail = $this->user->u_email;
    $toname = $this->user->u_fname . " " . $this->user->u_lname;
    $subject = "{$this->ListName} notification: {$toemail} has updated their user profile";
    $mhtml = "<p>Profile for $this->uid on " . $this->ListName . " has been updated.</p>";
    $mtext = "Profile for $this->uid on " . $this->ListName . " has been updated.\n";

    if ($this->mailer->OpenSMTP()) {
      $html .= "<p class=\"{{@pclass}\">SMTP server opened</p>";
      $count = $this->mailer->SendNotification($muid,$mtype,$mfrom,$toemail,$toname,$subject,$mhtml,$mtext);
      $html .= "<p class=\"{{@pclass}\">$count email sent to {$this->user->u_email}</p>";
      $this->mailer->CloseSMTP();
    } else {
      $html .= "<p class=\"{{@pclass}\">SMTP server did not open</p>";
    }
    return $html;
  }

  public function DisplayProfileHTML() {
    $html = "";
    if (!$this->uloggedin) {
      $html .= "<p class=\"{{@pclass}\">Please login to view your profile.</p>";
      return $html;
    }

    $ff = new formfield;

    $html .= $ff->FF_DivOpen("{{@tableresponsive}}");
    $html .= $ff->FF_TableOpen("{{@tableclass}}");
    $html .= $ff->FF_TheadOpen("{{@theadclass}}");
    $html .= $ff->FF_TrOpen("{{@trclass}}");

    $html .= $ff->FF_Th("Photo","{{@thclasscenter}}");
    $html .= $ff->FF_Th("Personal Information","{{@thclasscenter}}"," colspan=4");
    // $html .= $ff->FF_Th("Contact","{{@thclass}}"," colspan=2");

    $html .= $ff->FF_TrClose();
    $html .= $ff->FF_TheadClose();
    $html .= $ff->FF_TbodyOpen("{{@tbodyclass}}");

    $html .= $ff->FF_TrOpen("");
    $photo = "";
    if ($this->user->u_photo <> '') {
      $photo .= "<a href=\"{$this->user->u_url}\"><img width='200' height='200' src=\"{$this->user->u_photo}\"></a>";
    } else {
      $photo .= "<a href=\"{$this->user->u_url}\"><img width='200' height='200' src='img/placeholder.jpg'></a>";
    }
    $html .= $ff->FF_Td($photo,"{{@tdclass}}"," rowspan=6");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("First name:","");
    $html .= $ff->FF_Td($this->user->u_fname,"");
    $html .= $ff->FF_Td("Last name:","");
    $html .= $ff->FF_Td($this->user->u_lname,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Email:","");
    $html .= $ff->FF_Td($this->user->u_email,"");
    $html .= $ff->FF_Td("Cell:","");
    $html .= $ff->FF_Td($this->user->u_phone,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Birthday:","");
    $html .= $ff->FF_Td($this->user->u_birthday,"");
    $html .= $ff->FF_Td("Gender:","");
    $html .= $ff->FF_Td($this->user->u_gender,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Province:","");
    $html .= $ff->FF_Td($this->user->u_province,"");
    $html .= $ff->FF_Td("Country:","");
    $html .= $ff->FF_Td($this->user->u_country,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TrOpen("");
    $html .= $ff->FF_Td("Company:","");
    $html .= $ff->FF_Td($this->user->u_business,"");
    $html .= $ff->FF_Td("Web site address:","");
    $html .= $ff->FF_Td($this->user->u_url,"");
    $html .= $ff->FF_TrClose();

    $html .= $ff->FF_TbodyClose();
    $html .= $ff->FF_TableClose();
    $html .= $ff->FF_DivClose();

    return $html;
  }

}
