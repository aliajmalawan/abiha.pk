<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Cart\CartException;
use App\Services\OrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.orders', ['orders' => $request->user()->orders()->withCount('items')->paginate(10)]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('account.order', ['order' => $order->load(['items', 'history'])]);
    }

    public function cancel(Request $request, Order $order, OrderService $orders): RedirectResponse
    {
        Gate::authorize('cancel', $order);
        try {
            $orders->cancel($order, $request->user());
        } catch (CartException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        \App\Services\OrderMailer::statusChanged($order->fresh(), 'cancelled');

        return back()->with('status', 'Your order has been cancelled.');
    }
}
