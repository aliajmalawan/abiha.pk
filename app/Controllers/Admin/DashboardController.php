<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\ProductService;
use App\Services\Admin\ReportService;

/** Admin overview (port of the old Admin\DashboardController). Every number comes from the database. */
final class DashboardController extends AdminController
{
    /** Orders that count as revenue (cancelled/returned never do). */
    private const COUNTED = "status NOT IN ('cancelled', 'returned')";

    public function index(Request $request): Response
    {
        return $this->adminView('admin/dashboard', self::data(), 'Dashboard');
    }

    /** Everything the dashboard shows (also used by the tests). */
    public static function data(): array
    {
        $db = Database::get();
        $c = self::COUNTED;
        $today = date('Y-m-d');
        $ago = static fn (int $days): string => date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Last 30 days vs the 30 days before, for the trend badge
        $now30 = (int) $db->value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE {$c} AND created_at >= ?", [$ago(30)]);
        $prev30 = (int) $db->value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE {$c} AND created_at BETWEEN ? AND ?", [$ago(60), $ago(30)]);
        $todayRow = $db->one("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS s FROM orders WHERE {$c} AND DATE(created_at) = ?", [$today]);

        $stats = [
            'revenue' => (int) $db->value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE {$c}"),
            'collected' => (int) $db->value("SELECT COALESCE(SUM(total), 0) FROM orders WHERE {$c} AND payment_status = 'paid'"),
            'orders' => (int) $db->value('SELECT COUNT(*) FROM orders'),
            'units_sold' => (int) $db->value("SELECT COALESCE(SUM(i.quantity), 0) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE o.status NOT IN ('cancelled', 'returned')"),
            'avg_order' => (int) round((float) $db->value("SELECT AVG(total) FROM orders WHERE {$c}")),
            'pending' => (int) $db->value("SELECT COUNT(*) FROM orders WHERE status = 'pending'"),
            'completed' => (int) $db->value("SELECT COUNT(*) FROM orders WHERE status = 'delivered'"),
            'customers' => (int) $db->value('SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.deleted_at IS NULL AND (u.role_id IS NULL OR r.is_staff = 0)'),
            'products' => (int) $db->value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL'),
            'products_active' => (int) $db->value('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND is_active = 1'),
            'low_stock' => (int) $db->value('SELECT COUNT(*) FROM products p WHERE p.deleted_at IS NULL AND ' . ProductService::LOW_STOCK_SQL),
            'out_of_stock' => (int) $db->value('SELECT COUNT(*) FROM products p WHERE p.deleted_at IS NULL AND p.is_active = 1 AND ' . ProductService::OUT_OF_STOCK_SQL),
            'today_orders' => (int) $todayRow['n'],
            'today_sales' => (int) $todayRow['s'],
            'to_verify' => (int) $db->value("SELECT COUNT(*) FROM orders WHERE payment_status = 'awaiting_verification'"),
            'new_messages' => (int) $db->value("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'"),
            'pending_reviews' => (int) $db->value('SELECT COUNT(*) FROM reviews WHERE is_approved = 0'),
        ];

        return [
            'stats' => $stats,
            'last30' => $now30,
            'trend' => $prev30 > 0 ? (int) round((($now30 - $prev30) / $prev30) * 100) : null,
            'daily' => ReportService::sales('day', date('Y-m-d', strtotime('-29 days')), $today),
            'months' => ReportService::monthlyRevenue(12),
            'recentOrders' => $db->all(ReportService::ORDER_SELECT . ' ORDER BY o.id DESC LIMIT 8'),
            // Best sellers are worked out from the orders themselves (cancelled and returned excluded), by units sold.
            'topProducts' => ReportService::productSales(date('Y-m-d', strtotime('-29 days')), $today, 5, 'units'),
            'topAllTime' => ReportService::productSales(date('Y-m-d', strtotime('-30 years')), $today, 5, 'units'),
        ];
    }
}
