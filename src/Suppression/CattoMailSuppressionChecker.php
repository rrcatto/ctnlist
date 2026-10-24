<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Subscriber\EmailNormaliser;

/**
 * SUPPRESSION_PROVIDER=cattomail: global suppression is catto-mail's, and
 * the installation has no banlist database. catto-mail refuses suppressed
 * recipients when a send is staged and suppresses hard-bounced and
 * complaining addresses itself (DeliveryProblems maps those outcomes back),
 * so ctnlist applies only the v5 address rules here and records nothing:
 * a global unsubscribe stays an unsubscribe from all local lists, and is
 * never reported to catto-mail (only GlobalOptOut's explicit request is).
 */
final class CattoMailSuppressionChecker implements SuppressionChecker
{
    public function isSuppressed(string $email): bool
    {
        return !EmailNormaliser::isValid(EmailNormaliser::correct($email));
    }

    public function suppressEmail(string $email, string $type, string $reason): bool
    {
        return false;
    }

    public function suppressDomain(string $domain, string $type): bool
    {
        return false;
    }

    public function keepsGlobalList(): bool
    {
        return false;
    }
}
