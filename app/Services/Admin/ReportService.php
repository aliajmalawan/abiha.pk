<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;

/**
 * All reporting queries (port of the old App\Services\ReportService). "Revenue" = orders that are not cancelled or
 * returned (money the business expects to keep). "Collected" = the part of that revenue whose payment_status is paid.
 * Dates are business time (Asia/Karachi), ranges are whole days: $from / $to are "Y-m-d" (or any date string).
 * Rows are arrays with integer money/count fields.
 */
final class ReportService
{
    public const EXCLUDED = ['cancelled', 'returned'];

    /** "Y-m-d 00:00:00" / "Y-m-d 23:59:59" bounds of a day range. @return array{0:string,1:string} */
    public static function bounds(string $from, string $to): array
    {
        return [date('Y-m-d 00:00:00', (int) strtotime($from)), date('Y-m-d 23:59:59', (int) strtotime($to))];
    }

    /** @return list<array{period:string,orders:int,gross:int,discount:int,shipping:int,tax:int,revenue:int,collected:int}> */
    public static function sales(string $period, string $from, string $to): array
    {
        $expr = match ($period) {
            'year' => "DATE_FORMAT(created_at, '%Y')",
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => 'DATE(created_at)',
        };
        $rows = Database::get()->all(
            "SELECT {$expr} AS period, COUNT(*) AS orders, SUM(subtotal) AS gross, SUM(discount) AS discount, SUM(shipping_cost) AS shipping,
                    SUM(tax_amount) AS tax, SUM(total) AS revenue, SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END) AS collected
             FROM orders WHERE status NOT IN ('cancelled', 'returned') AND created_at BETWEEN ? AND ?
             GROUP BY period ORDER BY period",
            self::bounds($from, $to)
        );
        return array_map(static fn (array $r): array => [
            'period' => (string) $r['period'], 'orders' => (int) $r['orders'], 'gross' => (int) $r['gross'], 'discount' => (int) $r['discount'],
            'shipping' => (int) $r['shipping'], 'tax' => (int) $r['tax'], 'revenue' => (int) $r['revenue'], 'collected' => (int) $r['collected'],
        ], $rows);
    }

    /**
     * Last N calendar months (oldest first), zero-filled, for the dashboard chart.
     *
     * @return list<array{label:string,year:string,period:string,revenue:int,orders:int}>
     */
    public static function monthlyRevenue(int $months = 12): array
    {
        $start = (new \DateTimeImmutable('first day of this month 00:00:00'))->modify('-' . ($months - 1) . ' months');
        $byPeriod = array_column(self::sales('month', $start->format('Y-m-d'), date('Y-m-d')), null, 'period');
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->modify("+{$i} months");
            $row = $byPeriod[$m->format('Y-m')] ?? null;
            $out[] = ['label' => $m->format('M'), 'year' => $m->format('Y'), 'period' => $m->format('Y-m'), 'revenue' => $row['revenue'] ?? 0, 'orders' => $row['orders'] ?? 0];
        }
        return $out;
    }

    /**
     * Sales per product line (name + SKU snapshot). $by: 'revenue' (reports) or 'units' (best sellers).
     *
     * @return list<array{name:string,sku:?string,units:int,revenue:int,orders:int}>
     */
    public static function productSales(string $from, string $to, int $limit = 200, string $by = 'revenue'): array
    {
        $order = $by === 'units' ? 'units DESC, revenue DESC' : 'revenue DESC';
        $rows = Database::get()->all(
            "SELECT i.name AS name, i.sku AS sku, SUM(i.quantity) AS units, SUM(i.total) AS revenue, COUNT(DISTINCT i.order_id) AS orders
             FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE o.status NOT IN ('cancelled', 'returned') AND o.created_at BETWEEN ? AND ?
             GROUP BY i.name, i.sku ORDER BY {$order} LIMIT " . max(1, $limit),
            self::bounds($from, $to)
        );
        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'sku' => $r['sku'], 'units' => (int) $r['units'], 'revenue' => (int) $r['revenue'], 'orders' => (int) $r['orders']], $rows);
    }

    /** Buyers by checkout email (guests included): orders, spend, last order. @return list<array> */
    public static function customers(string $from, string $to, int $limit = 200): array
    {
        $rows = Database::get()->all(
            "SELECT o.email AS email, MAX(a.name) AS name, COUNT(*) AS orders, SUM(o.total) AS spent, MAX(o.created_at) AS last_order,
                    MAX(CASE WHEN o.user_id IS NULL THEN 0 ELSE 1 END) AS has_account
             FROM orders o LEFT JOIN order_addresses a ON a.order_id = o.id AND a.type = 'shipping'
             WHERE o.status NOT IN ('cancelled', 'returned') AND o.created_at BETWEEN ? AND ?
             GROUP BY o.email ORDER BY spent DESC LIMIT " . max(1, $limit),
            self::bounds($from, $to)
        );
        return array_map(static fn (array $r): array => ['email' => (string) $r['email'], 'name' => $r['name'], 'orders' => (int) $r['orders'], 'spent' => (int) $r['spent'],
            'last_order' => (string) $r['last_order'], 'account' => (bool) (int) $r['has_account']], $rows);
    }

    /** New customer accounts (not staff) created in the range. */
    public static function newCustomers(string $from, string $to): int
    {
        return (int) Database::get()->value(
            'SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id = u.role_id
             WHERE u.deleted_at IS NULL AND (u.role_id IS NULL OR r.is_staff = 0) AND u.created_at BETWEEN ? AND ?',
            self::bounds($from, $to)
        );
    }

    /** Breakdown by payment method (counted orders) and by order status (all orders, nothing hidden). @return array{method:list<array>,status:list<array>} */
    public static function revenueBreakdown(string $from, string $to): array
    {
        $db = Database::get();
        $b = self::bounds($from, $to);
        $cast = static fn (array $rows): array => array_map(static fn (array $r): array => ['label' => (string) $r['label'], 'orders' => (int) $r['orders'], 'revenue' => (int) $r['revenue'], 'collected' => (int) $r['collected']], $rows);
        return [
            'method' => $cast($db->all(
                "SELECT payment_method AS label, COUNT(*) AS orders, SUM(total) AS revenue, SUM(CASE WHEN payment_status = 'paid' THEN total ELSE 0 END) AS collected
                 FROM orders WHERE created_at BETWEEN ? AND ? AND status NOT IN ('cancelled', 'returned') GROUP BY payment_method ORDER BY revenue DESC", $b)),
            'status' => $cast($db->all(
                'SELECT status AS label, COUNT(*) AS orders, SUM(total) AS revenue, 0 AS collected FROM orders WHERE created_at BETWEEN ? AND ? GROUP BY status ORDER BY orders DESC', $b)),
        ];
    }

    /**
     * Orders in range (with the shipping name), filtered by status / payment / method.
     * Returns [sql without ORDER BY, params]; callers add their own ordering / limits.
     *
     * @return array{0:string,1:list<mixed>}
     */
    public static function ordersQuery(string $from, string $to, array $filters = []): array
    {
        $where = ['o.created_at BETWEEN ? AND ?'];
        $params = self::bounds($from, $to);
        foreach (['status' => 'o.status', 'payment' => 'o.payment_status', 'method' => 'o.payment_method'] as $k => $col) {
            if (!empty($filters[$k])) {
                $where[] = "{$col} = ?";
                $params[] = (string) $filters[$k];
            }
        }
        return [self::ORDER_SELECT . ' WHERE ' . implode(' AND ', $where), $params];
    }

    /** Orders with the shipping-address snapshot (name, phone, province, city, address) as plain columns. */
    public const ORDER_SELECT = "SELECT o.*, a.name AS name, a.phone AS phone, a.province AS province, a.city AS city, a.address AS address, a.postal_code AS postal_code
        FROM orders o LEFT JOIN order_addresses a ON a.id = (SELECT a2.id FROM order_addresses a2 WHERE a2.order_id = o.id AND a2.type = 'shipping' ORDER BY a2.id LIMIT 1)";
}
