<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Subscriber\EmailNormaliser;
use Psr\Log\LoggerInterface;

/**
 * SUPPRESSION_PROVIDER=none (dev and test only): nothing is globally
 * suppressed, but unusable addresses still are, and nothing can be recorded.
 */
final class NullSuppressionChecker implements SuppressionChecker
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function isSuppressed(string $email): bool
    {
        return !EmailNormaliser::isValid(EmailNormaliser::correct($email));
    }

    public function suppressEmail(string $email, string $type, string $reason): bool
    {
        $this->logger->warning('Global unsubscribe not recorded: no suppression provider is configured.');
        return false;
    }

    public function suppressDomain(string $domain, string $type): bool
    {
        $this->logger->warning('Global domain unsubscribe not recorded: no suppression provider is configured.');
        return false;
    }
}
