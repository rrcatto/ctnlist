<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Repository\CattoMailBlockRepository;

/**
 * Whatever the suppression provider, an administrator's block of an address or its whole
 * domain (cattomail_blocks, kept by catto-mail as an account block) suppresses the address
 * here too while it is pending or active: it is then never confirmed, queued or staged, and
 * the queue builder unsubscribes it from every list as it does a banlist entry. Recording
 * and the global list are the provider's.
 */
final class BlockAwareChecker implements SuppressionChecker
{
    public function __construct(
        private readonly SuppressionChecker $provider,
        private readonly CattoMailBlockRepository $blocks,
    ) {
    }

    public function isSuppressed(string $email): bool
    {
        return $this->provider->isSuppressed($email) || $this->blocks->isBlocked(trim($email));
    }

    public function suppressEmail(string $email, string $type, string $reason): bool
    {
        return $this->provider->suppressEmail($email, $type, $reason);
    }

    public function suppressDomain(string $domain, string $type): bool
    {
        return $this->provider->suppressDomain($domain, $type);
    }

    public function keepsGlobalList(): bool
    {
        return $this->provider->keepsGlobalList();
    }
}
