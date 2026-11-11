<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(ReportService $reports): View
    {
        $counted = fn () => Order::query()->whereNotIn('status', ReportService::EXCLUDED);   // cancelled/returned never count as revenue
        $customers = User::query()->where(fn ($q) => $q->whereNull('role_id')->orWhereHas('role', fn ($r) => $r->where('is_staff', false)));

        // Last 30 days vs the 30 days before, for the trend badge
        $now30 = $counted()->where('created_at', '>=', now()->subDays(30))->sum('total');
        $prev30 = $counted()->whereBetween('created_at', [now()->subDays(60), now()->subDays(30)])->sum('total');

        $month = $reports->monthlyRevenue(12);

        return view('admin.dashboard', [
            'stats' => [
                'revenue' => (int) $counted()->sum('total'),
                'collected' => (int) $counted()->where('payment_status', 'paid')->sum('total'),
                'orders' => Order::query()->count(),
                'units_sold' => (int) OrderItem::query()->whereHas('order', fn ($o) => $o->whereNotIn('status', ReportService::EXCLUDED))->sum('quantity'),
                'avg_order' => (int) round((float) $counted()->avg('total')),
                'pending' => Order::query()->where('status', 'pending')->count(),
                'completed' => Order::query()->where('status', 'delivered')->count(),
                'customers' => $customers->count(),
                'products' => Product::query()->count(),
                'products_active' => Product::query()->where('is_active', true)->count(),
                'low_stock' => Product::query()->lowStock()->count(),
                'out_of_stock' => Product::query()->where('is_active', true)->outOfStock()->count(),
                'today_orders' => $counted()->whereDate('created_at', today())->count(),
                'today_sales' => (int) $counted()->whereDate('created_at', today())->sum('total'),
                'to_verify' => Order::query()->where('payment_status', 'awaiting_verification')->count(),
                'new_messages' => ContactMessage::query()->where('status', 'new')->count(),
                'pending_reviews' => Review::query()->where('is_approved', false)->count(),
            ],
            'last30' => (int) $now30,
            'trend' => $prev30 > 0 ? round((($now30 - $prev30) / $prev30) * 100) : null,
            'daily' => $reports->sales('day', now()->subDays(29), now()),
            'months' => $month,
            'recentOrders' => Order::query()->latest('id')->limit(8)->get(),
            // Best sellers are worked out from the orders themselves (cancelled and returned excluded), by units sold.
            'topProducts' => $reports->productSales(now()->subDays(29), now(), 5, 'units'),
            'topAllTime' => $reports->productSales(now()->subYears(30), now(), 5, 'units'),
        ]);
    }
}
