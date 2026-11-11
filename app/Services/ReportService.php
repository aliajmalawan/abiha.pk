<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * All reporting queries. "Revenue" = orders that are not cancelled or returned (money the business expects to keep).
 * "Collected" = the part of that revenue whose payment_status is paid. Dates are in the app timezone.
 */
class ReportService
{
    public const EXCLUDED = ['cancelled', 'returned'];

    /** @return Collection<int, object{period:string,orders:int,gross:int,discount:int,shipping:int,tax:int,revenue:int,collected:int}> */
    public function sales(string $period, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $expr = $this->periodExpr($period);

        return DB::table('orders')
            ->whereNotIn('status', self::EXCLUDED)->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("{$expr} as period, count(*) as orders, sum(subtotal) as gross, sum(discount) as discount, sum(shipping_cost) as shipping, sum(tax_amount) as tax, sum(total) as revenue, sum(case when payment_status = 'paid' then total else 0 end) as collected")
            ->groupBy('period')->orderBy('period')->get()
            ->map(fn ($r) => (object) ['period' => (string) $r->period, 'orders' => (int) $r->orders, 'gross' => (int) $r->gross, 'discount' => (int) $r->discount,
                'shipping' => (int) $r->shipping, 'tax' => (int) $r->tax, 'revenue' => (int) $r->revenue, 'collected' => (int) $r->collected]);
    }

    /** Last N calendar months (oldest first), zero-filled, for the dashboard chart. */
    public function monthlyRevenue(int $months = 12): Collection
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $rows = $this->sales('month', $start, now())->keyBy('period');

        return collect(range(0, $months - 1))->map(function ($i) use ($start, $rows) {
            $m = $start->copy()->addMonths($i);
            $row = $rows->get($m->format('Y-m'));

            return (object) ['label' => $m->format('M'), 'year' => $m->format('Y'), 'period' => $m->format('Y-m'), 'revenue' => $row->revenue ?? 0, 'orders' => $row->orders ?? 0];
        });
    }

    /** @return Collection<int, object{name:string,sku:string,units:int,revenue:int,orders:int}> */
    /** @param  string  $by  'revenue' (default, used by reports) or 'units' (best sellers) */
    public function productSales(CarbonInterface $from, CarbonInterface $to, int $limit = 200, string $by = 'revenue'): Collection
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotIn('o.status', self::EXCLUDED)->whereBetween('o.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('i.name as name, i.sku as sku, sum(i.quantity) as units, sum(i.total) as revenue, count(distinct i.order_id) as orders')
            ->groupBy('i.name', 'i.sku')->orderByDesc($by === 'units' ? 'units' : 'revenue')->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn ($r) => (object) ['name' => $r->name, 'sku' => $r->sku, 'units' => (int) $r->units, 'revenue' => (int) $r->revenue, 'orders' => (int) $r->orders]);
    }

    /** Buyers by checkout email (guests included): orders, spend, last order. */
    public function customers(CarbonInterface $from, CarbonInterface $to, int $limit = 200): Collection
    {
        return DB::table('orders as o')->leftJoin('order_addresses as a', fn ($j) => $j->on('a.order_id', '=', 'o.id')->where('a.type', 'shipping'))
            ->whereNotIn('o.status', self::EXCLUDED)->whereBetween('o.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('o.email as email, max(a.name) as name, count(*) as orders, sum(o.total) as spent, max(o.created_at) as last_order, max(case when o.user_id is null then 0 else 1 end) as has_account')
            ->groupBy('o.email')->orderByDesc('spent')->limit($limit)->get()
            ->map(fn ($r) => (object) ['email' => $r->email, 'name' => $r->name, 'orders' => (int) $r->orders, 'spent' => (int) $r->spent, 'last_order' => $r->last_order, 'account' => (bool) $r->has_account]);
    }

    public function newCustomers(CarbonInterface $from, CarbonInterface $to): int
    {
        return User::query()->where(fn ($q) => $q->whereNull('role_id')->orWhereHas('role', fn ($r) => $r->where('is_staff', false)))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count();
    }

    /** Breakdown by payment method and by order status (cancelled/returned shown so nothing is hidden). */
    public function revenueBreakdown(CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => DB::table('orders')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        return [
            'method' => $base()->whereNotIn('status', self::EXCLUDED)->selectRaw("payment_method as label, count(*) as orders, sum(total) as revenue, sum(case when payment_status = 'paid' then total else 0 end) as collected")->groupBy('payment_method')->orderByDesc('revenue')->get(),
            'status' => $base()->selectRaw('status as label, count(*) as orders, sum(total) as revenue, 0 as collected')->groupBy('status')->orderByDesc('orders')->get(),
        ];
    }

    /** Orders in range (lazy cursor for CSV). */
    public function orders(CarbonInterface $from, CarbonInterface $to, array $filters = [])
    {
        return Order::query()->with('shippingAddress')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['payment'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->orderBy('id');
    }

    /** SQL expression grouping created_at by day/month/year on MySQL and SQLite. */
    private function periodExpr(string $period): string
    {
        $mysql = DB::connection()->getDriverName() !== 'sqlite';

        return match ($period) {
            'year' => $mysql ? "DATE_FORMAT(created_at, '%Y')" : "strftime('%Y', created_at)",
            'month' => $mysql ? "DATE_FORMAT(created_at, '%Y-%m')" : "strftime('%Y-%m', created_at)",
            default => $mysql ? 'DATE(created_at)' : "strftime('%Y-%m-%d', created_at)",
        };
    }
}
