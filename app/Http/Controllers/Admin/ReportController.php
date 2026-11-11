<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\ReportService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public const TYPES = [
        'sales' => 'Sales (daily / monthly / yearly)', 'products' => 'Product sales', 'customers' => 'Customers',
        'revenue' => 'Revenue breakdown', 'orders' => 'Orders',
    ];

    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): View|StreamedResponse
    {
        $f = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'period' => ['nullable', 'in:day,month,year'],
            'from' => ['nullable', 'bail', 'string', 'date'], 'to' => ['nullable', 'bail', 'string', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'export' => ['nullable', 'in:csv'],
        ]);

        $type = $f['type'] ?? 'sales';
        $period = $f['period'] ?? 'day';
        $to = Carbon::parse($f['to'] ?? now())->endOfDay();
        // default window per period so a "yearly" report is not an empty month
        $from = Carbon::parse($f['from'] ?? match ($period) { 'year' => now()->subYears(4)->startOfYear(), 'month' => now()->subMonths(11)->startOfMonth(), default => now()->subDays(29) })->startOfDay();
        abort_if($from->diffInDays($to) > 1830, 422, 'Please choose a range of 5 years or less.');

        if (($f['export'] ?? null) === 'csv') {
            ActivityLogger::log('report.exported', null, ['type' => $type, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

            return $this->export($type, $period, $from, $to, $f);
        }

        return view('admin.reports', [
            'type' => $type, 'period' => $period, 'from' => $from, 'to' => $to, 'types' => self::TYPES, 'status' => $f['status'] ?? null,
            'sales' => $type === 'sales' ? $this->reports->sales($period, $from, $to) : null,
            'products' => $type === 'products' ? $this->reports->productSales($from, $to) : null,
            'customers' => $type === 'customers' ? $this->reports->customers($from, $to) : null,
            'newCustomers' => $type === 'customers' ? $this->reports->newCustomers($from, $to) : null,
            'breakdown' => $type === 'revenue' ? $this->reports->revenueBreakdown($from, $to) : null,
            'orders' => $type === 'orders' ? $this->reports->orders($from, $to, $f)->latest('id')->limit(200)->get() : null,
            'ordersTotal' => $type === 'orders' ? $this->reports->orders($from, $to, $f)->count() : null,
        ]);
    }

    private function export(string $type, string $period, Carbon $from, Carbon $to, array $f): StreamedResponse
    {
        $name = "report-{$type}-{$from->format('Ymd')}-{$to->format('Ymd')}.csv";

        return match ($type) {
            'sales' => Csv::download($name, [ucfirst($period), 'Orders', 'Gross sales', 'Discounts', 'Shipping', 'Tax included', 'Revenue', 'Collected (paid)'],
                $this->reports->sales($period, $from, $to)->map(fn ($r) => [$r->period, $r->orders, $r->gross, $r->discount, $r->shipping, $r->tax, $r->revenue, $r->collected])),
            'products' => Csv::download($name, ['Product', 'SKU', 'Units sold', 'Orders', 'Revenue'],
                $this->reports->productSales($from, $to, 5000)->map(fn ($r) => [$r->name, $r->sku, $r->units, $r->orders, $r->revenue])),
            'customers' => Csv::download($name, ['Email', 'Name', 'Orders', 'Total spent', 'Last order', 'Has account'],
                $this->reports->customers($from, $to, 5000)->map(fn ($r) => [$r->email, $r->name, $r->orders, $r->spent, $r->last_order, $r->account ? 'yes' : 'guest'])),
            'revenue' => $this->revenueCsv($name, $from, $to),
            default => app(OrderController::class)->exportRange($name, $from, $to, $f),
        };
    }

    private function revenueCsv(string $name, Carbon $from, Carbon $to): StreamedResponse
    {
        $b = $this->reports->revenueBreakdown($from, $to);
        $rows = [];
        foreach ($b['method'] as $r) {
            $rows[] = ['Payment method', $r->label, $r->orders, $r->revenue, $r->collected];
        }
        foreach ($b['status'] as $r) {
            $rows[] = ['Order status', $r->label, $r->orders, $r->revenue, ''];
        }

        return Csv::download($name, ['Group', 'Name', 'Orders', 'Order value', 'Collected'], $rows);
    }
}
