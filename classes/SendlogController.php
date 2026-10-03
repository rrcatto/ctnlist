<?php
/*

Module: SendlogController class
Version: 5.0.1
Author: Richard Catto
Original Creation Date: 2017-07-12

*/

class SendlogController extends Controller
{
    protected Base $fat;
    protected string $BaseURL;
    public SendlogM $sendlog;

    public function __construct(Base $fat)
    {
        $this->fat = $fat;
        $this->BaseURL = (string) $fat->get('BaseURL');
        $this->sendlog = new SendlogM($fat);
    }

    public function SendlogCount(): int
    {
        return $this->sendlog->sendlogcount();
    }

    public function logSend($muid, $msgtype, $email, $listname, $subject, $subscriberToken = null): bool
    {
        $this->sendlog->reset();
        $this->sendlog->sl_muid = (string) $muid;
        $this->sendlog->sl_datesent = date('Y-m-d H:i:s');
        $this->sendlog->sl_type = (string) $msgtype;
        $this->sendlog->sl_email = (string) $email;
        $this->sendlog->sl_list_shortcode = strtoupper(trim((string) $listname));
        $token = trim((string) $subscriberToken);
        $this->sendlog->sl_s_uuid = $token !== '' ? $token : null;
        $this->sendlog->sl_subject = (string) $subject;
        $this->sendlog->save();
        return true;
    }

    public function ShowGroupedStats(): string
    {
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="mb-3"><strong>All time:</strong> ';
        foreach ($this->sendlog->groupedByType() as $row) {
            $type = (string) $row['sl_type'];
            $html .= '<a href="' . $e($this->BaseURL . 'sendlog?t=' . rawurlencode($type))
                . '" class="btn btn-primary btn-sm mx-1">' . $e($type)
                . ' <span class="badge bg-light text-dark">' . (int) $row['sl_total'] . '</span></a>';
        }
        $html .= '</div>';

        $current = '';
        foreach ($this->sendlog->groupedByMonthAndType() as $row) {
            $month = sprintf('%04d/%02d', (int) $row['sl_year'], (int) $row['sl_month']);
            if ($current !== $month) {
                if ($current !== '') {
                    $html .= '</div>';
                }
                $html .= '<div class="mb-2"><strong>' . $e($month) . ':</strong> ';
                $current = $month;
            }
            $type = (string) $row['sl_type'];
            $html .= '<a href="' . $e($this->BaseURL . 'sendlog?t=' . rawurlencode($type))
                . '" class="btn btn-outline-primary btn-sm mx-1">' . $e($type)
                . ' <span class="badge bg-primary">' . (int) $row['sl_total'] . '</span></a>';
        }
        if ($current !== '') {
            $html .= '</div>';
        }
        return $html;
    }

    /** Legacy compatibility name retained for existing administrator code. */
    public function ShowStats(): string
    {
        return $this->ShowGroupedStats();
    }


    public function CreateSltypeHTMLDropDown($sltype = ''): string
    {
        // Retain known workflow types even before the first matching email has
        // been sent, while also exposing any future type already in Send Log.
        $types = [
            'MESSAGE', 'PROOF', 'RESEND', 'FORWARD-MESSAGE', 'FORWARD-NOTIFICATION',
            'SUBSCRIBE', 'CONFIRM', 'UNSUBSCRIBE', 'UPDATE-PROFILE', 'UPDATE-USER',
            'MAGIC-LINK', 'CONTACT',
        ];
        $types = array_values(array_unique(array_merge($types, $this->sendlog->sendTypes())));
        sort($types, SORT_STRING);
        $html = '<option value=""' . ($sltype === '' ? ' selected' : '') . '>All Types</option>';
        foreach ($types as $type) {
            $safe = htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<option value="' . $safe . '"' . ($sltype === $type ? ' selected' : '') . '>' . $safe . '</option>';
        }
        return $html;
    }

    public function CreateSendlogHTMLList($ssemail = '', $stype = '', $pageno = 1, $numrows = 25): string
    {
        if (!Controller::allowed($this->fat, 'logs.view')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $ssemail = trim((string) $ssemail);
        $stype = trim((string) $stype);
        $pageno = max(1, (int) $pageno);
        $numrows = max(1, min(200, (int) $numrows));
        $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = $this->ShowGroupedStats();
        $html .= '<form action="{{@BaseURL}}sendlog" method="get" class="card card-body mb-4"><div class="row g-3">'
            . '<div class="col-md-5"><label class="form-label">Email address</label><input class="form-control" type="search" name="e" value="' . $e($ssemail) . '"></div>'
            . '<div class="col-md-4"><label class="form-label">Message type</label><select class="form-select" name="t">' . $this->CreateSltypeHTMLDropDown($stype) . '</select></div>'
            . '<div class="col-md-2"><label class="form-label">Rows</label><input class="form-control" type="number" min="1" max="200" name="r" value="' . $numrows . '"></div>'
            . '<div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary" type="submit">Search</button></div>'
            . '</div></form>';

        $filter = [
            'sl_type LIKE :sltype AND LOWER(sl_email) LIKE LOWER(:email)',
            ':sltype' => $stype . '%',
            ':email' => '%' . $ssemail . '%',
        ];
        $total = (int) $this->sendlog->count($filter);
        if ($total === 0) {
            return $html . '<p class="{{@pclass}}">No emails match that search.</p>';
        }
        $lastPage = max(1, (int) ceil($total / $numrows));
        $pageno = min($pageno, $lastPage);
        $page = $this->sendlog->paginate($pageno - 1, $numrows, $filter, ['order' => 'sl_datesent DESC, sl_id DESC']);

        $html .= '<div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr>'
            . '<th>Sent</th><th>Type</th><th>Email</th><th>List</th><th>Subject</th><th>Subscriber UUID</th>'
            . '</tr></thead><tbody>';
        foreach ($page['subset'] as $row) {
            $html .= '<tr><td>' . $e($row['sl_datesent']) . '</td><td>' . $e($row['sl_type']) . '</td>'
                . '<td>' . $e($row['sl_email']) . '</td><td>' . $e($row['sl_list_shortcode']) . '</td>'
                . '<td>' . $e($row['sl_subject']) . '</td><td><code>' . $e($row['sl_s_uuid']) . '</code></td></tr>';
        }
        $html .= '</tbody></table></div>';
        $query = http_build_query(array_filter(['e' => $ssemail, 't' => $stype, 'r' => $numrows], static fn($v) => $v !== ''));
        return $html . (new htmlhelper($this->fat))->paginate($page, 'sendlog', $query === '' ? '' : '?' . $query);
    }
}
