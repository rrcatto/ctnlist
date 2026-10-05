<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Site Log report queries (writes go through App\Log\SiteLog). */
final class SiteLogRepository
{
    /** Filter keys, as the report form names them. */
    public const FILTERS = ['q', 'e', 'u', 'ip', 'li', 'from', 'to'];

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
            'SELECT stl_logged_at, stl_email, stl_url, stl_ip, stl_xfwdfor, stl_host, stl_logged_in, stl_agent FROM sitelog' . $where
            . ' ORDER BY stl_logged_at DESC, stl_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
            $types
        );
    }

    /**
     * v5 filters: keyword across email, URL, IP, host, forwarded-for and
     * agent; substrings for email, URL and IP (IP or forwarded-for); login
     * state; dates inclusive (to = before the next day).
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
                ['stl_email', 'stl_url', 'stl_ip', 'stl_host', 'stl_xfwdfor', 'stl_agent']
            )) . ')';
            $params['keyword'] = '%' . $f('q') . '%';
        }
        if ($f('e') !== '') {
            $parts[] = "LOWER(COALESCE(stl_email, '')) LIKE LOWER(:email)";
            $params['email'] = '%' . $f('e') . '%';
        }
        if ($f('u') !== '') {
            $parts[] = "LOWER(COALESCE(stl_url, '')) LIKE LOWER(:url)";
            $params['url'] = '%' . $f('u') . '%';
        }
        if ($f('ip') !== '') {
            $parts[] = "(COALESCE(stl_ip, '') LIKE :ip OR COALESCE(stl_xfwdfor, '') LIKE :ip)";
            $params['ip'] = '%' . $f('ip') . '%';
        }
        if ($f('li') === '1' || $f('li') === '0') {
            $parts[] = 'stl_logged_in = :logged';
            $params['logged'] = $f('li') === '1';
            $types['logged'] = ParameterType::BOOLEAN;
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
