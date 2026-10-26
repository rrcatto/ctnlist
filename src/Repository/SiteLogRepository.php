<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Site Log report queries (writes go through App\Log\SiteLog). */
final class SiteLogRepository
{
    /** Filter keys, as the report form names them. */
    public const FILTERS = ['q', 'e', 'u', 'ip', 'li', 'fl', 'from', 'to'];

    /** The public pages whose forms send mail to the address typed in (Flagged activity). */
    public const FORM_PATHS = ['/login', '/subscribe', '/subscribe/request', '/contact-form'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string, string> $filters see FILTERS */
    public function count(array $filters): int
    {
        [$where, $params, $types] = self::where($filters);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM sitelog' . $where, $params, $types);
    }

    /**
     * @param array<string, string> $filters see FILTERS
     * @return list<array<string, mixed>> newest first
     */
    public function page(array $filters, int $offset, int $limit): array
    {
        [$where, $params, $types] = self::where($filters);
        return $this->db->fetchAllAssociative(
            'SELECT stl_logged_at, stl_email, stl_method, stl_url, stl_status, stl_ip, stl_xfwdfor, stl_host, stl_logged_in, stl_agent, stl_flag, stl_flag_email FROM sitelog' . $where
            . ' ORDER BY stl_logged_at DESC, stl_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
            $types
        );
    }

    /**
     * Form submissions refused as automated (BotCheck), newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function refused(int $limit): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT stl_logged_at, stl_method, stl_url, stl_ip, stl_host, stl_agent, stl_flag, stl_flag_email FROM sitelog
             WHERE stl_flag IS NOT NULL ORDER BY stl_logged_at DESC, stl_id DESC LIMIT ' . max(1, min(1000, $limit))
        );
    }

    /**
     * Requests for the pages in FORM_PATHS, newest first.
     *
     * @return list<array{stl_logged_at: string, stl_ip: ?string, stl_host: ?string, stl_agent: ?string, stl_flag: ?string}>
     */
    public function formRequests(int $limit): array
    {
        /** @var list<array{stl_logged_at: string, stl_ip: ?string, stl_host: ?string, stl_agent: ?string, stl_flag: ?string}> */
        return $this->db->fetchAllAssociative(
            'SELECT stl_logged_at, stl_ip, stl_host, stl_agent, stl_flag FROM sitelog WHERE stl_url IN (?)
             ORDER BY stl_logged_at DESC, stl_id DESC LIMIT ' . max(1, min(100000, $limit)),
            [self::FORM_PATHS],
            [ArrayParameterType::STRING]
        );
    }

    /**
     * v5 filters: keyword across email, URL, IP, host, forwarded-for, agent
     * and a refused form's address; substrings for email, URL and IP (IP or
     * forwarded-for); login state; refused form submissions only (fl=1);
     * dates inclusive (to = before the next day).
     *
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private static function where(array $filters): array
    {
        $f = static fn(string $key): string => trim($filters[$key] ?? '');
        $parts = [];
        $params = [];
        $types = [];
        if ($f('q') !== '') {
            $parts[] = '(' . implode(' OR ', array_map(
                static fn(string $column): string => "LOWER(COALESCE({$column}, '')) LIKE LOWER(:keyword)",
                ['stl_email', 'stl_url', 'stl_ip', 'stl_host', 'stl_xfwdfor', 'stl_agent', 'stl_flag_email']
            )) . ')';
            $params['keyword'] = Like::contains($f('q'));
        }
        if ($f('e') !== '') {
            $parts[] = "LOWER(COALESCE(stl_email, '')) LIKE LOWER(:email)";
            $params['email'] = Like::contains($f('e'));
        }
        if ($f('u') !== '') {
            $parts[] = "LOWER(COALESCE(stl_url, '')) LIKE LOWER(:url)";
            $params['url'] = Like::contains($f('u'));
        }
        if ($f('ip') !== '') {
            $parts[] = "(COALESCE(stl_ip, '') LIKE :ip OR COALESCE(stl_xfwdfor, '') LIKE :ip)";
            $params['ip'] = Like::contains($f('ip'));
        }
        if ($f('li') === '1' || $f('li') === '0') {
            $parts[] = 'stl_logged_in = :logged';
            $params['logged'] = $f('li') === '1';
            $types['logged'] = ParameterType::BOOLEAN;
        }
        if ($f('fl') === '1') {
            $parts[] = 'stl_flag IS NOT NULL';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f('from')) === 1) {
            $parts[] = 'stl_logged_at >= :date_from';
            $params['date_from'] = $f('from') . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f('to')) === 1) {
            $parts[] = 'stl_logged_at < :date_to';
            $params['date_to'] = date('Y-m-d H:i:s', (int) strtotime($f('to') . ' +1 day'));
        }
        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params, $types];
    }
}
