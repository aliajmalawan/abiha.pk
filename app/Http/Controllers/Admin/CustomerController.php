<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    /** Customers = accounts without admin-area access. */
    private function customers()
    {
        return User::query()->where(fn ($q) => $q->whereNull('role_id')->orWhereHas('role', fn ($r) => $r->where('is_staff', false)));
    }

    public function index(Request $request): View
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,blocked']]);
        $like = isset($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;

        $customers = $this->customers()->withCount('orders')
            ->withSum(['orders as spent' => fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned'])], 'total')
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->when(($f['status'] ?? null) === 'blocked', fn ($q) => $q->where('is_active', false))
            ->when(($f['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString();

        return view('admin.customers.index', ['customers' => $customers, 'filters' => $f]);
    }

    /** CSV of all customers with order count and lifetime spend (cancelled/returned excluded). */
    public function export(): StreamedResponse
    {
        ActivityLogger::log('customers.exported');
        $rows = (function () {
            $q = $this->customers()->withCount('orders')->withSum(['orders as spent' => fn ($o) => $o->whereNotIn('status', ['cancelled', 'returned'])], 'total');
            foreach ($q->lazyById(500) as $c) {
                yield [$c->name, $c->email, $c->phone, $c->created_at->format('Y-m-d'), $c->orders_count, (int) $c->spent, $c->is_active ? 'active' : 'blocked', $c->last_login_at?->format('Y-m-d')];
            }
        })();

        return Csv::download('customers-'.now()->format('Ymd').'.csv', ['Name', 'Email', 'Phone', 'Joined', 'Orders', 'Total spent', 'Status', 'Last sign-in'], $rows);
    }

    public function show(int $id): View
    {
        $customer = $this->customers()->with('addresses')->findOrFail($id);

        return view('admin.customers.show', [
            'customer' => $customer,
            'orders' => $customer->orders()->withCount('items')->limit(25)->get(),
            'spent' => (int) $customer->orders()->whereNotIn('status', ['cancelled', 'returned'])->sum('total'),
            'orderCount' => $customer->orders()->count(),
        ]);
    }

    /** Block / unblock sign-in. Order history is untouched. */
    public function toggleActive(Request $request, int $id): RedirectResponse
    {
        $customer = $this->customers()->findOrFail($id);
        $customer->forceFill(['is_active' => ! $customer->is_active])->save();
        ActivityLogger::log($customer->is_active ? 'customer.unblocked' : 'customer.blocked', $customer);

        return back()->with('status', $customer->is_active ? 'Customer unblocked.' : 'Customer blocked from signing in.');
    }
}
