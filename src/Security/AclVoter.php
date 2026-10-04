<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants ACL permission keys such as `lists.manage` (is_granted /
 * #[IsGranted]). Administrators hold every permission, as in v5.
 *
 * @extends Voter<string, mixed>
 */
final class AclVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return preg_match('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $attribute) === 1;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        return $user instanceof SubscriberUser && ($user->isAdministrator() || $user->hasPermission($attribute));
    }
}
