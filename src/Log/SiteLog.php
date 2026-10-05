<?php

declare(strict_types=1);

namespace App\Log;

use App\Security\SubscriberUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Site Log (`sitelog`): one row per request (v5). A failure to write it is
 * logged and never affects the page.
 */
final class SiteLog
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(Request $request, ?SubscriberUser $user): void
    {
        try {
            $ip = mb_substr((string) $request->getClientIp(), 0, 45);
            $forwarded = trim((string) $request->headers->get('X-Forwarded-For'));
            $host = $ip !== '' ? @gethostbyaddr($ip) : false;
            $this->db->insert('sitelog', [
                'stl_email' => $user?->email,
                'stl_url' => mb_substr($request->getPathInfo(), 0, 253),
                'stl_ip' => $ip,
                'stl_host' => is_string($host) ? mb_substr($host, 0, 253) : null,
                'stl_xfwdfor' => $forwarded !== '' ? mb_substr($forwarded, 0, 45) : null,
                'stl_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 500),
                'stl_logged_in' => $user !== null,
                'stl_logged_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ], ['stl_logged_in' => ParameterType::BOOLEAN]);
        } catch (\Throwable $e) {
            $this->logger->error('Site Log could not be written: {message}', ['message' => $e->getMessage()]);
        }
    }
}
