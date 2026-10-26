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
 * Site Log (`sitelog`): one row per request (v5), with its method and
 * response status, and for a form submission refused as automated
 * (App\Http\BotCheck) why and the address typed. A failure to write it is
 * logged and never affects the page.
 */
final class SiteLog
{
    /** The request attribute flag() sets for record(). */
    private const FLAG = '_site_log_flag';

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The path as recorded. Query strings are never logged (the sign-in token
     * travels there); credentials carried in the path are masked: the HMAC of
     * a one-click unsubscribe link would let any reader of the log use it.
     */
    public static function loggablePath(string $path): string
    {
        return (string) preg_replace('#^(/unsubscribe-link/[^/]+/[^/]+/[^/]+/)[^/]+#', '$1[signature]', $path);
    }

    /**
     * Mark this request's form submission as refused as automated: $reason is
     * one of BotCheck's, $email the address typed into the form.
     */
    public static function flag(Request $request, string $reason, string $email): void
    {
        $request->attributes->set(self::FLAG, [$reason, $email]);
    }

    public function record(Request $request, int $status, ?SubscriberUser $user): void
    {
        $flag = $request->attributes->get(self::FLAG);
        $reason = is_array($flag) && is_string($flag[0] ?? null) ? $flag[0] : '';
        $email = is_array($flag) && is_string($flag[1] ?? null) ? $flag[1] : '';
        try {
            $ip = mb_substr((string) $request->getClientIp(), 0, 45);
            $forwarded = trim((string) $request->headers->get('X-Forwarded-For'));
            $host = $ip !== '' ? @gethostbyaddr($ip) : false;
            $this->db->insert('sitelog', [
                'stl_email' => $user?->email,
                'stl_method' => mb_substr($request->getMethod(), 0, 10),
                'stl_url' => mb_substr(self::loggablePath($request->getPathInfo()), 0, 253),
                'stl_status' => $status,
                'stl_ip' => $ip,
                'stl_host' => is_string($host) ? mb_substr($host, 0, 253) : null,
                'stl_xfwdfor' => $forwarded !== '' ? mb_substr($forwarded, 0, 45) : null,
                'stl_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 500),
                'stl_logged_in' => $user !== null,
                'stl_flag' => $reason !== '' ? mb_substr($reason, 0, 100) : null,
                'stl_flag_email' => $reason !== '' ? mb_substr($email, 0, 254) : null,
                'stl_logged_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ], ['stl_status' => ParameterType::INTEGER, 'stl_logged_in' => ParameterType::BOOLEAN]);
        } catch (\Throwable $e) {
            $this->logger->error('Site Log could not be written: {message}', ['message' => $e->getMessage()]);
        }
    }
}
