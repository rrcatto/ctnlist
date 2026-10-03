<?php

declare(strict_types=1);

final class ListsController extends Controller
{
    private \DB\SQL $db;
    private ListService $lists;

    public function __construct(private Base $fat)
    {
        $this->db = $fat->get('dbPDO');
        $this->lists = new ListService($fat, $this->db);
    }

    public function index(): string
    {
        if (!Controller::allowed($this->fat, 'lists.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $csrf = Csrf::field($this->fat);
        $html = '<div class="row g-4"><div class="col-lg-7"><h1 class="h4">Lists</h1>';
        $html .= '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Shortcode</th><th>Name</th><th>Type</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($this->lists->all(false) as $list) {
            $system = filter_var($list['l_system'], FILTER_VALIDATE_BOOLEAN);
            $active = filter_var($list['l_active'], FILTER_VALIDATE_BOOLEAN);
            $html .= '<tr><td>' . htmlspecialchars((string) $list['l_shortcode']) . '</td><td>' . htmlspecialchars((string) $list['l_name']) . '</td>';
            $html .= '<td>' . ($system ? 'System' : 'Custom') . '</td><td>' . ($active ? 'Active' : 'Inactive') . '</td><td>';
            if (!$system) {
                $html .= '<form action="{{@BaseURL}}lists/delete" method="post" onsubmit="return confirm(\'Delete this list?\')">'
                    . $csrf . '<input type="hidden" name="list_id" value="' . (int) $list['l_id'] . '"><button class="btn btn-sm btn-outline-danger">Delete</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';
        $html .= '<div class="col-lg-5"><form action="{{@BaseURL}}lists" method="post" class="card card-body">' . $csrf;
        $html .= '<h2 class="h5">Create a topic list</h2>';
        $html .= '<div class="mb-3"><label class="form-label">Shortcode</label><input class="form-control" name="shortcode" minlength="5" maxlength="6" pattern="[A-Za-z0-9]{5,6}" required><div class="form-text">5–6 letters or numbers.</div></div>';
        $html .= '<div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="100" required></div>';
        $html .= '<div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="5"></textarea></div>';
        $html .= '<button class="btn btn-primary" type="submit">Create list</button></form></div></div>';
        return $html;
    }

    public function create(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'lists.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        try {
            $id = $this->lists->create(
                (string) $this->fat->get('POST.shortcode'),
                (string) $this->fat->get('POST.name'),
                (string) $this->fat->get('POST.description')
            );
            return '<p class="{{@pclass}}">List created with ID ' . $id . '.</p>';
        } catch (\Throwable $e) {
            return '<p class="{{@pclass}}">Unable to create list: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    public function delete(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'lists.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $id = (int) $this->fat->get('POST.list_id');
        $list = $this->lists->findById($id);
        if ($list === null) {
            $this->fat->error(404);
        }
        if (filter_var($list['l_system'], FILTER_VALIDATE_BOOLEAN)) {
            return '<p class="{{@pclass}}">System lists cannot be deleted.</p>';
        }
        try {
            $this->db->exec('DELETE FROM lists WHERE l_id = :id AND l_system = FALSE', [':id' => $id]);
            return '<p class="{{@pclass}}">List deleted.</p>';
        } catch (\Throwable $e) {
            return '<p class="{{@pclass}}">The list cannot be deleted while messages or other records refer to it.</p>';
        }
    }
}