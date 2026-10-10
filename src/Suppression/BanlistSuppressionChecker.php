<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Config\SiteConfig;
use App\Subscriber\EmailNormaliser;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Suppression stored in the shared banlist database (`globalunsubscribe`,
 * `globaldomainunsubscribe`). Records carry this installation's instance id
 * and domain.
 */
final class BanlistSuppressionChecker implements SuppressionChecker
{
    public function __construct(
        private readonly Connection $banlist,
        private readonly SiteConfig $site,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isSuppressed(string $email): bool
    {
        $email = EmailNormaliser::correct($email);
        if (!EmailNormaliser::isValid($email)) {
            return true;
        }
        return $this->banlist->fetchOne(
            'SELECT 1 FROM globalunsubscribe WHERE gu_email = ? AND gu_active = 1
             UNION ALL
             SELECT 1 FROM globaldomainunsubscribe WHERE gdu_domain_name = ? AND gdu_active = 1
             LIMIT 1',
            [$email, EmailNormaliser::domain($email)]
        ) !== false;
    }

    public function suppressEmail(string $email, string $type, string $reason): bool
    {
        // Stored as isSuppressed() looks it up: after the v5 cleanup rules (bulk input arrives uncorrected).
        $email = EmailNormaliser::correct($email);
        if (!EmailNormaliser::isValid($email)) {
            return false;
        }
        try {
            $this->banlist->executeStatement(
                'INSERT INTO globalunsubscribe (gu_api, gu_domain, gu_email, gu_type, gu_reason)
                 VALUES (:api, :domain, :email, :type, :reason)
                 ON CONFLICT (gu_email) DO UPDATE
                 SET gu_active = 1, gu_api = EXCLUDED.gu_api, gu_domain = EXCLUDED.gu_domain,
                     gu_type = EXCLUDED.gu_type, gu_reason = EXCLUDED.gu_reason',
                [
                    'api' => $this->site->instanceId,
                    'domain' => $this->site->domain,
                    'email' => $email,
                    'type' => $type,
                    'reason' => mb_substr($reason, 0, 255),
                ]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Global unsubscribe save failed: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function suppressDomain(string $domain, string $type): bool
    {
        try {
            $this->banlist->executeStatement(
                'INSERT INTO globaldomainunsubscribe (gdu_api, gdu_domain, gdu_domain_name, gdu_type)
                 VALUES (:api, :domain, :name, :type)
                 ON CONFLICT (gdu_domain_name) DO UPDATE
                 SET gdu_active = 1, gdu_api = EXCLUDED.gdu_api, gdu_domain = EXCLUDED.gdu_domain, gdu_type = EXCLUDED.gdu_type',
                ['api' => $this->site->instanceId, 'domain' => $this->site->domain, 'name' => $domain, 'type' => $type]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Global domain unsubscribe save failed: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }
}
