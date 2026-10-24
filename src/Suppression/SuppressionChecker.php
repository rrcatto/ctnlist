<?php

declare(strict_types=1);

namespace App\Suppression;

/**
 * Global email/domain suppression shared by all installations (the banlist),
 * or only the v5 address rules where catto-mail's suppression stands in for it.
 * Checked at confirmation, queue build and immediately before every campaign send.
 */
interface SuppressionChecker
{
    /**
     * True when the address (after the v5 cleanup rules) is unusable, or the
     * address or its domain is globally suppressed.
     */
    public function isSuppressed(string $email): bool;

    /**
     * Record a global email suppression; returns false when it could not be
     * recorded. Types: ADMIN, BOUNCE, BOUNCE-ADMIN, INVALID, SPAM, SPAM-ADMIN, USER.
     */
    public function suppressEmail(string $email, string $type, string $reason): bool;

    /** Record a global domain suppression. Types: NOTEXIST, SPAM. */
    public function suppressDomain(string $domain, string $type): bool;

    /**
     * False when the installation keeps no global suppression list of its own
     * (SUPPRESSION_PROVIDER cattomail or none): nothing can then be recorded.
     */
    public function keepsGlobalList(): bool;
}
