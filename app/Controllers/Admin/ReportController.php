<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityLogger;
use App\Services\Admin\ReportService;
use App\Services\OrderService;

/** Reports with CSV downloads (port of the old Admin\ReportController). */
final class ReportController extends AdminController
{
    public const TYPES = [
        'sales' => 'Sales (daily / monthly / yearly)', 'products' => 'Product sales', 'customers' => 'Customers',
        'revenue' => 'Revenue breakdown', 'orders' => 'Orders',
    ];

    public function index(Request $request): Response
    {
        $f = $this->validate($request->query, [
            'type' => ['nullable', 'in:' . implode(',', array_keys(self::TYPES))],
            'period' => ['nullable', 'in:day,month,year'],
            'from' => ['nullable', 'bail', 'string', 'date'], 'to' => ['nullable', 'bail', 'string', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:' . implode(',', array_keys(OrderService::STATUSES))],
            'export' => ['nullable', 'in:csv'],
        ]);
        $type = $f['type'] ?? 'sales';
        $period = $f['period'] ?? 'day';
        $to = date('Y-m-d', (int) strtotime((string) ($f['to'] ?? 'today')));
        // default window per period so a "yearly" report is not an empty month
        $from = !empty($f['from']) ? date('Y-m-d', (int) strtotime((string) $f['from'])) : match ($period) {
            'year' => (new \DateTimeImmutable('first day of january this year'))->modify('-4 years')->format('Y-m-d'),
            'month' => (new \DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d'),
            default => date('Y-m-d', strtotime('-29 days')),
        };
        if (abs((strtotime($to) - strtotime($from)) / 86400) > 1830) {
            throw new HttpException(422, 'Please choose a range of 5 years or less.');
        }

        if (($f['export'] ?? null) === 'csv') {
            ActivityLogger::log('report.exported', null, null, ['type' => $type, 'from' => $from, 'to' => $to]);
            return $this->export($type, $period, $from, $to, $f);
        }

        $orders = $ordersTotal = null;
        if ($type === 'orders') {
            [$sql, $params] = ReportService::ordersQuery($from, $to, $f);
            $db = Database::get();
            $orders = $db->all($sql . ' ORDER BY o.id DESC LIMIT 200', $params);
            $ordersTotal = (int) $db->value("SELECT COUNT(*) FROM ({$sql}) x", $params);
        }

        return $this->adminView('admin/reports', [
            'type' => $type, 'period' => $period, 'from' => $from, 'to' => $to, 'types' => self::TYPES, 'status' => $f['status'] ?? null,
            'sales' => $type === 'sales' ? ReportService::sales($period, $from, $to) : null,
            'products' => $type === 'products' ? ReportService::productSales($from, $to) : null,
            'customers' => $type === 'customers' ? ReportService::customers($from, $to) : null,
            'newCustomers' => $type === 'customers' ? ReportService::newCustomers($from, $to) : null,
            'breakdown' => $type === 'revenue' ? ReportService::revenueBreakdown($from, $to) : null,
            'orders' => $orders,
            'ordersTotal' => $ordersTotal,
        ], 'Reports');
    }

    private function export(string $type, string $period, string $from, string $to, array $f): Response
    {
        $name = 'report-' . $type . '-' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to) . '.csv';
        switch ($type) {
            case 'sales':
                return InventoryController::csv($name, [ucfirst($period), 'Orders', 'Gross sales', 'Discounts', 'Shipping', 'Tax included', 'Revenue', 'Collected (paid)'],
                    array_map(static fn ($r) => [$r['period'], $r['orders'], $r['gross'], $r['discount'], $r['shipping'], $r['tax'], $r['revenue'], $r['collected']], ReportService::sales($period, $from, $to)));
            case 'products':
                return InventoryController::csv($name, ['Product', 'SKU', 'Units sold', 'Orders', 'Revenue'],
                    array_map(static fn ($r) => [$r['name'], $r['sku'], $r['units'], $r['orders'], $r['revenue']], ReportService::productSales($from, $to, 5000)));
            case 'customers':
                return InventoryController::csv($name, ['Email', 'Name', 'Orders', 'Total spent', 'Last order', 'Has account'],
                    array_map(static fn ($r) => [$r['email'], $r['name'], $r['orders'], $r['spent'], $r['last_order'], $r['account'] ? 'yes' : 'guest'], ReportService::customers($from, $to, 5000)));
            case 'revenue':
                $b = ReportService::revenueBreakdown($from, $to);
                $rows = [];
                foreach ($b['method'] as $r) {
                    $rows[] = ['Payment method', $r['label'], $r['orders'], $r['revenue'], $r['collected']];
                }
                foreach ($b['status'] as $r) {
                    $rows[] = ['Order status', $r['label'], $r['orders'], $r['revenue'], ''];
                }
                return InventoryController::csv($name, ['Group', 'Name', 'Orders', 'Order value', 'Collected'], $rows);
            default:
                [$sql, $params] = ReportService::ordersQuery($from, $to, $f);
                return OrderController::exportRange($name, $sql, $params);
        }
    }
}
