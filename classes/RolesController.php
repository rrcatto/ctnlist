<?php

declare(strict_types=1);

final class RolesController extends Controller
{
    private \DB\SQL $db;
    private AclService $acl;

    public function __construct(private Base $fat)
    {
        $this->db = $fat->get('dbPDO');
        $this->acl = new AclService($fat, $this->db);
    }

    public function index(): string
    {
        if (!Controller::allowed($this->fat, 'roles.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $csrf = Csrf::field($this->fat);
        $roles = $this->db->exec('SELECT r_id, r_key, r_name, r_description, r_system FROM roles ORDER BY r_system DESC, r_name');
        $permissions = $this->db->exec('SELECT ap_id, ap_key, ap_name FROM acl_permissions ORDER BY ap_key');
        $html = '<h1 class="h4">Roles and access control</h1>';
        foreach ($roles as $role) {
            $granted = $this->db->exec('SELECT rp_ap_id FROM role_permissions WHERE rp_r_id = :rid', [':rid' => $role['r_id']]);
            $grantedIds = array_map(static fn(array $row): int => (int) $row['rp_ap_id'], $granted);
            $system = filter_var($role['r_system'], FILTER_VALIDATE_BOOLEAN);
            $html .= '<form action="{{@BaseURL}}roles/permissions" method="post" class="card card-body mb-3">' . $csrf;
            $html .= '<input type="hidden" name="role_id" value="' . (int) $role['r_id'] . '">';
            $html .= '<h2 class="h5">' . htmlspecialchars((string) $role['r_name']) . ($system ? ' <span class="badge text-bg-secondary">System</span>' : '') . '</h2>';
            $html .= '<p>' . htmlspecialchars((string) $role['r_description']) . '</p><div class="row">';
            foreach ($permissions as $permission) {
                $checked = in_array((int) $permission['ap_id'], $grantedIds, true) ? ' checked' : '';
                $disabled = $system ? ' disabled' : '';
                $html .= '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="permission_ids[]" value="' . (int) $permission['ap_id'] . '" id="rp-' . (int) $role['r_id'] . '-' . (int) $permission['ap_id'] . '"' . $checked . $disabled . '><label class="form-check-label" for="rp-' . (int) $role['r_id'] . '-' . (int) $permission['ap_id'] . '">' . htmlspecialchars((string) $permission['ap_key']) . '</label></div></div>';
            }
            $html .= '</div>';
            if (!$system) {
                $html .= '<button class="btn btn-primary mt-3" type="submit">Save permissions</button>';
            }
            $html .= '</form>';
        }

        $html .= '<form action="{{@BaseURL}}roles" method="post" class="card card-body mb-4">' . $csrf;
        $html .= '<h2 class="h5">Create custom role</h2><div class="row g-3">';
        $html .= '<div class="col-md-4"><label class="form-label">Key</label><input class="form-control" name="key" pattern="[a-z0-9._-]+" required></div>';
        $html .= '<div class="col-md-4"><label class="form-label">Name</label><input class="form-control" name="name" required></div>';
        $html .= '<div class="col-md-4"><label class="form-label">Description</label><input class="form-control" name="description"></div>';
        $html .= '<div class="col-12"><button class="btn btn-primary">Create role</button></div></div></form>';

        $subscribers = $this->db->exec('SELECT s_id, s_email FROM subscribers ORDER BY s_email LIMIT 500');
        $html .= '<form action="{{@BaseURL}}roles/assign" method="post" class="card card-body">' . $csrf;
        $html .= '<h2 class="h5">Assign role to subscriber</h2><div class="row g-3">';
        $html .= '<div class="col-md-6"><select class="form-select" name="subscriber_id">';
        foreach ($subscribers as $subscriber) {
            $html .= '<option value="' . (int) $subscriber['s_id'] . '">' . htmlspecialchars((string) $subscriber['s_email']) . '</option>';
        }
        $html .= '</select></div><div class="col-md-4"><select class="form-select" name="role_key">';
        foreach ($roles as $role) {
            $html .= '<option value="' . htmlspecialchars((string) $role['r_key']) . '">' . htmlspecialchars((string) $role['r_name']) . '</option>';
        }
        $html .= '</select></div><div class="col-md-2"><button class="btn btn-primary">Assign</button></div></div></form>';
        return $html;
    }

    public function create(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'roles.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $key = strtolower(trim((string) $this->fat->get('POST.key')));
        $name = trim((string) $this->fat->get('POST.name'));
        $description = trim((string) $this->fat->get('POST.description'));
        if (!preg_match('/^[a-z0-9._-]+$/', $key) || $name === '') {
            return '<p class="{{@pclass}}">Invalid role details.</p>';
        }
        $this->db->exec(
            'INSERT INTO roles (r_key, r_name, r_description) VALUES (:key, :name, :description)',
            [':key' => $key, ':name' => $name, ':description' => $description]
        );
        return '<p class="{{@pclass}}">Role created.</p>';
    }

    public function savePermissions(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'acl.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $roleId = (int) $this->fat->get('POST.role_id');
        $rows = $this->db->exec('SELECT r_system FROM roles WHERE r_id = :id', [':id' => $roleId]);
        if ($rows === []) {
            $this->fat->error(404);
        }
        if (filter_var($rows[0]['r_system'], FILTER_VALIDATE_BOOLEAN)) {
            return '<p class="{{@pclass}}">System-role permissions are fixed by the schema.</p>';
        }
        $permissionIds = array_values(array_unique(array_filter(array_map('intval', (array) $this->fat->get('POST.permission_ids')), static fn(int $id): bool => $id > 0)));
        $this->db->begin();
        try {
            $this->db->exec('DELETE FROM role_permissions WHERE rp_r_id = :rid', [':rid' => $roleId]);
            foreach ($permissionIds as $permissionId) {
                $this->db->exec(
                    'INSERT INTO role_permissions (rp_r_id, rp_ap_id) VALUES (:rid, :pid) ON CONFLICT DO NOTHING',
                    [':rid' => $roleId, ':pid' => $permissionId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        return '<p class="{{@pclass}}">Permissions updated.</p>';
    }

    public function assign(): string
    {
        Csrf::requireValid($this->fat);
        if (!Controller::allowed($this->fat, 'roles.manage')) {
            return '<p class="{{@pclass}}">Access denied.</p>';
        }
        $this->acl->assignRole(
            (int) $this->fat->get('POST.subscriber_id'),
            (string) $this->fat->get('POST.role_key'),
            (int) $this->fat->get('SESSION.subscriber_id') ?: null
        );
        return '<p class="{{@pclass}}">Role assigned.</p>';
    }
}