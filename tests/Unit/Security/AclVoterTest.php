<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\AclVoter;
use App\Security\SubscriberUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class AclVoterTest extends TestCase
{
    /** @return iterable<string, array{list<string>, list<string>, string, int}> */
    public static function votes(): iterable
    {
        yield 'permission held' => [['subscriber'], ['lists.manage'], 'lists.manage', VoterInterface::ACCESS_GRANTED];
        yield 'permission missing' => [['subscriber'], ['logs.view'], 'lists.manage', VoterInterface::ACCESS_DENIED];
        yield 'administrator holds every permission' => [['administrator'], [], 'acl.manage', VoterInterface::ACCESS_GRANTED];
        yield 'roles are not ACL attributes' => [['administrator'], [], 'ROLE_ADMINISTRATOR', VoterInterface::ACCESS_ABSTAIN];
    }

    /**
     * @param list<string> $roleKeys
     * @param list<string> $permissions
     */
    #[DataProvider('votes')]
    public function testVote(array $roleKeys, array $permissions, string $attribute, int $expected): void
    {
        $user = new SubscriberUser(1, '01a10309-8535-7ba9-a26a-e7ae433bb2e7', 'a@ctnlist.test', 'A', 'B', $roleKeys, $permissions);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        self::assertSame($expected, (new AclVoter())->vote($token, null, [$attribute]));
    }

    public function testAnonymousIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new AclVoter())->vote(new NullToken(), null, ['lists.manage']));
    }

    public function testRolesDeriveFromRoleKeys(): void
    {
        $user = new SubscriberUser(1, 'u', 'a@ctnlist.test', '', '', ['administrator', 'list-editor'], []);
        self::assertSame(['ROLE_USER', 'ROLE_ADMINISTRATOR', 'ROLE_LIST_EDITOR'], $user->getRoles());
        self::assertTrue($user->isAdministrator());
    }
}
