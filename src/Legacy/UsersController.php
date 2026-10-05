<?php

declare(strict_types=1);

namespace App\Legacy;

use App\Security\SubscriberUser;
use Base;

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

    protected bool $administrator = false;
    /** @var list<string> */
    protected array $permissions = [];

    public SubscribersM $user;
    public bool $uloggedin = false;
    public int $uadmin = 0;
    public int $uid = 0;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        $this->dbPDO = $fat->get('dbPDO');
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->ListName = (string) $fat->get('ListName');
        $this->FromAddress = (string) $fat->get('FromAddress');
        $this->AdminEmail = (string) $fat->get('AdminEmail');
        $this->AdminName = (string) $fat->get('AdminName');

        $this->user = new SubscribersM($fat);

        $this->clearUserContext();
    }

    /**
     * Adopt the subscriber authenticated by Symfony Security. LegacyBridge
     * calls this before any other legacy object reads the user context.
     */
    public function authenticateAs(SubscriberUser $subscriber): void
    {
        $this->user->load(['s_id = :sid', ':sid' => $subscriber->id]);
        if ($this->user->dry()) {
            return;
        }
        $this->administrator = $subscriber->isAdministrator();
        $this->permissions = $subscriber->permissions;
        $this->setUserContext();
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
        return $this->uloggedin && in_array($permission, $this->permissions, true);
    }








    private function setUserContext(): void
    {
        $this->uloggedin = true;
        $this->uid = (int) $this->user->s_id;
        $this->uadmin = $this->administrator ? 1 : 0;
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
        $this->fat->set('acl_permissions', $this->permissions);
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









}