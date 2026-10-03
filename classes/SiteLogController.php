<?php

declare(strict_types=1);

/**
 * Records each request and provides the administrator-facing Site Log report.
 *
 * Site access logging is deliberately independent of the report: a failure to
 * write the log must never prevent the requested page from loading.
 */
class SiteLogController extends Controller
{
    private SiteLogM $sitelog;

    public function __construct(private Base $fat)
    {
        $this->sitelog = new SiteLogM($fat);
        $this->recordAccess();
    }

    private function recordAccess(): void
    {
        try {
            $this->sitelog->reset();
            $this->sitelog->stl_email = trim((string) $this->fat->get('Email')) ?: null;
            $this->sitelog->stl_url = mb_substr((string) $this->fat->get('PATH'), 0, 253);
            $ip = mb_substr(trim((string) $this->fat->get('IP')), 0, 45);
            $this->sitelog->stl_ip = $ip;
            $this->sitelog->stl_host = $ip !== '' ? mb_substr((string) @gethostbyaddr($ip), 0, 253) : null;
            $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $this->sitelog->stl_xfwdfor = $forwarded !== '' ? mb_substr($forwarded, 0, 45) : null;
            $this->sitelog->stl_agent = mb_substr((string) $this->fat->get('AGENT'), 0, 500);
            $this->sitelog->stl_logged_in = (bool) $this->fat->get('uloggedin');
            $this->sitelog->save();
        } catch (\Throwable $e) {
            error_log('ctnlist sitelog: ' . $e->getMessage());
        }
    }

    public function CreateSiteLogHTMLList(
        string $keyword = '',
        string $email = '',
        string $url = '',
        string $ip = '',
        string $loggedIn = '',
        string $dateFrom = '',
        string $dateTo = '',
        int $pageNo = 1,
        int $numRows = 25
    ): string {
        if (!Controller::allowed($this->fat, 'logs.view')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }

        $pageNo = max(1, $pageNo);
        $numRows = max(1, min(200, $numRows));
        [$filter, $params] = $this->buildFilter($keyword, $email, $url, $ip, $loggedIn, $dateFrom, $dateTo);
        $mapperFilter = $filter === '' ? null : array_merge([$filter], $params);
        $total = (int) $this->sitelog->count($mapperFilter);

        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form method="get" action="{{@BaseURL}}sitelog" class="card card-body mb-4">'
            . '<h1 class="h4">Site Log</h1><div class="row g-3">'
            . '<div class="col-lg-4"><label class="form-label">Keyword</label><input class="form-control" name="q" value="' . $e($keyword) . '"></div>'
            . '<div class="col-lg-4"><label class="form-label">Email</label><input class="form-control" name="e" value="' . $e($email) . '"></div>'
            . '<div class="col-lg-4"><label class="form-label">URL</label><input class="form-control" name="u" value="' . $e($url) . '"></div>'
            . '<div class="col-lg-3"><label class="form-label">IP address</label><input class="form-control" name="ip" value="' . $e($ip) . '"></div>'
            . '<div class="col-lg-3"><label class="form-label">Login state</label><select class="form-select" name="li">'
            . '<option value=""' . ($loggedIn === '' ? ' selected' : '') . '>All</option>'
            . '<option value="1"' . ($loggedIn === '1' ? ' selected' : '') . '>Logged in</option>'
            . '<option value="0"' . ($loggedIn === '0' ? ' selected' : '') . '>Not logged in</option></select></div>'
            . '<div class="col-lg-3"><label class="form-label">From date</label><input class="form-control" type="date" name="from" value="' . $e($dateFrom) . '"></div>'
            . '<div class="col-lg-3"><label class="form-label">To date</label><input class="form-control" type="date" name="to" value="' . $e($dateTo) . '"></div>'
            . '<div class="col-lg-3"><label class="form-label">Rows</label><input class="form-control" type="number" min="1" max="200" name="r" value="' . $numRows . '"></div>'
            . '<div class="col-12"><button class="btn btn-primary" type="submit">Search Site Log</button> '
            . '<a class="btn btn-outline-secondary" href="{{@BaseURL}}sitelog">Clear</a></div></div></form>';

        if ($total === 0) {
            return $html . '<p class="{{@pclass}}">No site accesses match that search.</p>';
        }

        $lastPage = max(1, (int) ceil($total / $numRows));
        $pageNo = min($pageNo, $lastPage);
        $page = $this->sitelog->paginate($pageNo - 1, $numRows, $mapperFilter, ['order' => 'stl_logged_at DESC, stl_id DESC']);

        $html .= '<div class="table-responsive"><table class="table table-striped table-hover table-bordered align-middle">'
            . '<thead><tr><th>Accessed</th><th>Email</th><th>URL</th><th>Network</th><th>Login</th><th>User agent</th></tr></thead><tbody>';
        foreach ($page['subset'] as $row) {
            $network = $e($row['stl_ip'] ?? '');
            if (!empty($row['stl_xfwdfor'])) {
                $network .= '<br><small>Forwarded: ' . $e($row['stl_xfwdfor']) . '</small>';
            }
            if (!empty($row['stl_host'])) {
                $network .= '<br><small>Host: ' . $e($row['stl_host']) . '</small>';
            }
            $html .= '<tr><td>' . $e($row['stl_logged_at'] ?? '') . '</td>'
                . '<td>' . $e($row['stl_email'] ?? '') . '</td>'
                . '<td><code>' . $e($row['stl_url'] ?? '') . '</code></td>'
                . '<td>' . $network . '</td>'
                . '<td>' . (filter_var($row['stl_logged_in'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No') . '</td>'
                . '<td class="small">' . $e($row['stl_agent'] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $query = http_build_query(array_filter([
            'q' => $keyword,
            'e' => $email,
            'u' => $url,
            'ip' => $ip,
            'li' => $loggedIn,
            'from' => $dateFrom,
            'to' => $dateTo,
            'r' => $numRows,
        ], static fn(mixed $value): bool => $value !== ''));
        $html .= (new htmlhelper($this->fat))->paginate($page, 'sitelog', $query === '' ? '' : '?' . $query);
        return $html;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildFilter(
        string $keyword,
        string $email,
        string $url,
        string $ip,
        string $loggedIn,
        string $dateFrom,
        string $dateTo
    ): array {
        $parts = [];
        $params = [];
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $parts[] = '(LOWER(COALESCE(stl_email, \'\')) LIKE LOWER(:keyword)
                OR LOWER(COALESCE(stl_url, \'\')) LIKE LOWER(:keyword)
                OR LOWER(COALESCE(stl_ip, \'\')) LIKE LOWER(:keyword)
                OR LOWER(COALESCE(stl_host, \'\')) LIKE LOWER(:keyword)
                OR LOWER(COALESCE(stl_xfwdfor, \'\')) LIKE LOWER(:keyword)
                OR LOWER(COALESCE(stl_agent, \'\')) LIKE LOWER(:keyword))';
            $params[':keyword'] = '%' . $keyword . '%';
        }
        if (trim($email) !== '') {
            $parts[] = 'LOWER(COALESCE(stl_email, \'\')) LIKE LOWER(:email)';
            $params[':email'] = '%' . trim($email) . '%';
        }
        if (trim($url) !== '') {
            $parts[] = 'LOWER(COALESCE(stl_url, \'\')) LIKE LOWER(:url)';
            $params[':url'] = '%' . trim($url) . '%';
        }
        if (trim($ip) !== '') {
            $parts[] = '(COALESCE(stl_ip, \'\') LIKE :ip OR COALESCE(stl_xfwdfor, \'\') LIKE :ip)';
            $params[':ip'] = '%' . trim($ip) . '%';
        }
        if ($loggedIn === '1' || $loggedIn === '0') {
            $parts[] = 'stl_logged_in = :logged';
            $params[':logged'] = $loggedIn === '1';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $parts[] = 'stl_logged_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $parts[] = 'stl_logged_at < :date_to';
            $params[':date_to'] = date('Y-m-d H:i:s', strtotime($dateTo . ' +1 day'));
        }
        return [implode(' AND ', $parts), $params];
    }
}
