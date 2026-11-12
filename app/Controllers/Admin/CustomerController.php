<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\Pager;

/** Customers = accounts without admin-area access (port of the old Admin\CustomerController). */
final class CustomerController extends AdminController
{
    /** Customer accounts (no role, or a non-staff role), not deleted. Alias u. */
    private const CUSTOMERS = 'FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.deleted_at IS NULL AND (u.role_id IS NULL OR r.is_staff = 0)';
    private const STATS = "(SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS orders_count,
        (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.user_id = u.id AND o.status NOT IN ('cancelled', 'returned')) AS spent";

    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,blocked']]);
        $sql = 'SELECT u.*, ' . self::STATS . ' ' . self::CUSTOMERS;
        $params = [];
        if (!empty($f['q'])) {
            $like = Pager::like((string) $f['q']);
            $sql .= " AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.phone LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        if (($f['status'] ?? null) === 'blocked') {
            $sql .= ' AND u.is_active = 0';
        } elseif (($f['status'] ?? null) === 'active') {
            $sql .= ' AND u.is_active = 1';
        }
        $customers = Pager::query($request, $sql . ' ORDER BY u.id DESC', $params, (int) cfg('abiha.pagination.admin', 20));
        return $this->adminView('admin/customers/index', ['customers' => $customers, 'filters' => $f], 'Customers');
    }

    /** CSV of all customers with order count and lifetime spend (cancelled/returned excluded). */
    public function export(Request $request): Response
    {
        ActivityLogger::log('customers.exported');
        $rows = (static function (): \Generator {
            $db = Database::get();
            $last = 0;
            do {
                $batch = $db->all('SELECT u.*, ' . self::STATS . ' ' . self::CUSTOMERS . ' AND u.id > ? ORDER BY u.id LIMIT 500', [$last]);
                foreach ($batch as $c) {
                    $last = (int) $c['id'];
                    yield [$c['name'], $c['email'], $c['phone'], dt($c['created_at'], 'Y-m-d'), (int) $c['orders_count'], (int) $c['spent'], (int) $c['is_active'] === 1 ? 'active' : 'blocked', $c['last_login_at'] ? dt($c['last_login_at'], 'Y-m-d') : null];
                }
            } while (count($batch) === 500);
        })();
        return InventoryController::csv('customers-' . date('Ymd') . '.csv', ['Name', 'Email', 'Phone', 'Joined', 'Orders', 'Total spent', 'Status', 'Last sign-in'], $rows);
    }

    public function show(Request $request): Response
    {
        $customer = $this->customer((int) $request->params['id']);
        $db = Database::get();
        $id = (int) $customer['id'];
        return $this->adminView('admin/customers/show', [
            'customer' => $customer,
            'addresses' => $db->all('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC, id DESC', [$id]),
            'orders' => $db->all('SELECT o.*, (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS items_count FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC LIMIT 25', [$id]),
            'spent' => (int) $db->value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = ? AND status NOT IN ('cancelled', 'returned')", [$id]),
            'orderCount' => (int) $db->value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$id]),
        ], (string) $customer['name']);
    }

    /** Block / unblock sign-in. Order history is untouched. */
    public function toggleActive(Request $request): Response
    {
        $customer = $this->customer((int) $request->params['id']);
        $active = (int) $customer['is_active'] === 1 ? 0 : 1;
        Database::get()->exec('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?', [$active, now(), (int) $customer['id']]);
        ActivityLogger::log($active ? 'customer.unblocked' : 'customer.blocked', 'User', (int) $customer['id']);
        return $this->back(route('admin.customers.show', ['id' => $customer['id']]), $active ? 'Customer unblocked.' : 'Customer blocked from signing in.');
    }

    private function customer(int $id): array
    {
        $row = Database::get()->one('SELECT u.* ' . self::CUSTOMERS . ' AND u.id = ?', [$id]);
        if (!$row) {
            throw new HttpException(404);
        }
        unset($row['password'], $row['remember_token']);
        return $row;
    }
}
