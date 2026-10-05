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