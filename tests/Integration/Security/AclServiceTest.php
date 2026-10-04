<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\AclService;
use App\Security\SubscriberUserProvider;
use App\Tests\Integration\IntegrationTestCase;

final class AclServiceTest extends IntegrationTestCase
{
    public function testInitialAdministratorIsBootstrappedOnlyOnce(): void
    {
        $acl = $this->service(AclService::class);
        $this->db->executeStatement("DELETE FROM subscriber_roles WHERE sr_r_id = (SELECT r_id FROM roles WHERE r_key = 'administrator')");
        $owner = $this->createSubscriber('Owner@ctnlist.test');
        $other = $this->createSubscriber('other@ctnlist.test');

        self::assertFalse($acl->bootstrapInitialAdministrator($other, 'other@ctnlist.test'), 'only APP_ADMIN_EMAIL');
        self::assertTrue($acl->bootstrapInitialAdministrator($owner, 'Owner@ctnlist.test'), 'case-insensitive match');
        self::assertFalse($acl->bootstrapInitialAdministrator($owner, 'owner@ctnlist.test'), 'once an administrator exists');

        $user = $this->service(SubscriberUserProvider::class)->loadUserBySubscriberId($owner);
        self::assertTrue($user->isAdministrator());
        self::assertContains('ROLE_ADMINISTRATOR', $user->getRoles());
        self::assertContains('lists.manage', $user->permissions);
        self::assertSame(['subscriber'], $acl->roleKeys($other), 'new subscribers get the subscriber role');
    }
}
