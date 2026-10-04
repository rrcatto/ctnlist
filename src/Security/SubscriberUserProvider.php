<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\SubscriberRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<SubscriberUser> */
final class SubscriberUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly AclService $acl,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SubscriberUser
    {
        return $this->user($this->subscribers->findIdentityByUuid($identifier), $identifier);
    }

    public function loadUserBySubscriberId(int $id): SubscriberUser
    {
        return $this->user($this->subscribers->findIdentityById($id), (string) $id);
    }

    public function refreshUser(UserInterface $user): SubscriberUser
    {
        if (!$user instanceof SubscriberUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }
        return $this->loadUserBySubscriberId($user->id);
    }

    public function supportsClass(string $class): bool
    {
        return $class === SubscriberUser::class;
    }

    /** @param array{s_id: int, s_uuid: string, s_email: string, s_fname: string, s_lname: string}|null $identity */
    private function user(?array $identity, string $identifier): SubscriberUser
    {
        if ($identity === null) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }
        return new SubscriberUser(
            $identity['s_id'],
            $identity['s_uuid'],
            $identity['s_email'],
            $identity['s_fname'],
            $identity['s_lname'],
            $this->acl->roleKeys($identity['s_id']),
            $this->acl->permissions($identity['s_id']),
        );
    }
}
