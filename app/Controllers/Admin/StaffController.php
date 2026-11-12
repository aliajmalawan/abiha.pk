<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;

/**
 * Grant / change / revoke admin-area access for EXISTING accounts (port of the old Admin\StaffController). Requires
 * staff.manage. No account is ever created here, so there is never a default password.
 */
final class StaffController extends AdminController
{
    public function index(Request $request): Response
    {
        $db = Database::get();
        $roles = $db->all('SELECT * FROM roles WHERE is_staff = 1 ORDER BY id');
        foreach ($roles as &$r) {
            $r['permission_names'] = $db->column('SELECT p.name FROM permissions p JOIN permission_role pr ON pr.permission_id = p.id WHERE pr.role_id = ? ORDER BY p.id', [(int) $r['id']]);
        }
        unset($r);
        return $this->adminView('admin/staff/index', [
            'staff' => $db->all('SELECT u.id, u.name, u.email, u.is_active, u.role_id, r.name AS role_name, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
                                 WHERE r.is_staff = 1 AND u.deleted_at IS NULL ORDER BY u.name, u.id'),
            'roles' => $roles,
            'allRoles' => $db->all('SELECT id, name, slug, is_staff FROM roles ORDER BY id'),
        ], 'Staff & roles');
    }

    /** Give an existing account a staff role (looked up by email). */
    public function grant(Request $request): Response
    {
        $back = route('admin.staff.index');
        $data = $this->validate($request->post, ['email' => ['required', 'email'], 'role_id' => ['required', 'integer']]);
        $db = Database::get();
        $role = $db->one('SELECT * FROM roles WHERE id = ? AND is_staff = 1', [(int) $data['role_id']]);
        if (!$role) {
            throw new HttpException(404);
        }
        $user = $db->one('SELECT id, name FROM users WHERE email = ? AND deleted_at IS NULL', [mb_strtolower((string) $data['email'])]);
        if (!$user) {
            return $this->backWithErrors(['email' => 'No account with that email. Ask the person to register on the website first.'], $back);
        }
        $db->exec('UPDATE users SET role_id = ?, updated_at = ? WHERE id = ?', [(int) $role['id'], now(), (int) $user['id']]);
        ActivityLogger::log('staff.granted', 'User', (int) $user['id'], ['role' => $role['slug']]);
        return $this->back($back, "{$user['name']} is now {$role['name']}.");
    }

    public function update(Request $request): Response
    {
        $back = route('admin.staff.index');
        $db = Database::get();
        $user = $db->one('SELECT u.*, r.slug AS role_slug, r.is_staff AS role_is_staff FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.deleted_at IS NULL', [(int) $request->params['id']]);
        if (!$user) {
            throw new HttpException(404);
        }
        $data = $this->validate($request->post, ['role_id' => ['required', 'integer']]);
        $role = $db->one('SELECT * FROM roles WHERE id = ?', [(int) $data['role_id']]);
        if (!$role) {
            throw new HttpException(404);
        }
        if ((int) $user['id'] === Auth::id()) {
            return $this->backWithErrors(['role' => 'You cannot change your own role.'], $back);
        }
        $isSuper = $user['role_slug'] === Auth::SUPER_ADMIN && (int) $user['role_is_staff'] === 1;
        if ($isSuper && $role['slug'] !== Auth::SUPER_ADMIN && $this->activeAdmins() <= 1) {
            return $this->backWithErrors(['role' => 'At least one active administrator must remain.'], $back);
        }
        $db->exec('UPDATE users SET role_id = ?, updated_at = ? WHERE id = ?', [(int) $role['id'], now(), (int) $user['id']]);
        ActivityLogger::log('staff.role_changed', 'User', (int) $user['id'], ['from' => $user['role_slug'], 'to' => $role['slug']]);
        return $this->back($back, 'Role updated.');
    }

    private function activeAdmins(): int
    {
        return (int) Database::get()->value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND u.deleted_at IS NULL AND r.slug = 'admin'");
    }
}
