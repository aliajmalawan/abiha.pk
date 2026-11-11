<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\Cart\CartException;
use App\Services\OrderService;
use App\Services\Payments\PaymentService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Shipping\CourierException;
use App\Services\Shipping\CourierManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly PaymentService $payments)
    {
    }

    public function index(Request $request): View
    {
        $f = $this->filters($request);

        return view('admin.orders.index', [
            'orders' => $this->filtered($f)->withCount('items')->latest('id')->paginate(config('abiha.pagination.admin'))->withQueryString(),
            'filters' => $f,
            'counts' => Order::query()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    /** CSV of the orders matching the current list filters (plus optional date range). */
    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        ActivityLogger::log('orders.exported', null, array_filter($f));

        return $this->stream('orders-'.now()->format('Ymd-His').'.csv', $this->filtered($f));
    }

    /** Used by Reports → Orders. */
    public function exportRange(string $name, Carbon $from, Carbon $to, array $f = []): StreamedResponse
    {
        return $this->stream($name, app(\App\Services\ReportService::class)->orders($from, $to, $f));
    }

    public function show(Order $order): View
    {
        $order->load(['items', 'shipment.events.user:id,name', 'paymentTransactions.user:id,name', 'history.user:id,name', 'payments.verifier:id,name', 'user:id,name,email']);

        return view('admin.orders.show', [
            'couriers' => app(CourierManager::class)->available(),
            'order' => $order,
            'nextStatuses' => Order::TRANSITIONS[$order->status] ?? [],
            'canVerify' => in_array($order->payment_method, ['bank_transfer', 'cod'], true) && $order->payment_status !== 'paid' && $order->status !== 'cancelled' && $order->status !== 'returned',
            'canRefund' => $order->payment_status === 'paid' && in_array($order->status, ['cancelled', 'returned'], true),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'note' => ['nullable', 'string', 'max:190'],
            'restock' => ['nullable', 'boolean'],
        ]);

        try {
            $this->orders->changeStatus($order, $data['status'], $request->user(), $data['note'] ?? null, $request->boolean('restock'));
        } catch (CartException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        \App\Services\OrderMailer::statusChanged($order->fresh(), $data['status']);

        return back()->with('status', 'Order status updated.');
    }

    public function updateTracking(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'courier' => ['nullable', 'string', 'max:80'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        $clean = fn (?string $v) => $v !== null ? (trim(strip_tags($v)) ?: null) : null;
        $this->orders->setTracking($order, $clean($data['tracking_number'] ?? null), $data['tracking_url'] ?? null, $request->user(), $clean($data['courier'] ?? null));

        return back()->with('status', 'Shipping details saved.');
    }

    /** Book the parcel through a connected courier API. Only possible when a courier integration is installed and configured. */
    public function bookShipment(Request $request, Order $order, CourierManager $couriers): RedirectResponse
    {
        $courier = $couriers->find((string) $request->input('courier'));
        abort_unless($courier, 404);
        if ($order->tracking_number || ! in_array($order->status, ['confirmed', 'processing'], true)) {
            return back()->withErrors(['shipment' => 'Only a confirmed or processing order without a tracking number can be booked.']);
        }

        try {
            $shipment = $courier->createShipment($order);
        } catch (CourierException $e) {
            return back()->withErrors(['shipment' => $courier->label().': '.$e->getMessage()]);
        }

        $record = $order->saveShipment(['courier' => $courier->label(), 'courier_reference' => $shipment->reference, 'tracking_number' => $shipment->trackingNumber, 'tracking_url' => $shipment->trackingUrl]);
        $record->logEvent('booked', 'Booked with '.$courier->label(), 'staff', $request->user()->id);
        ActivityLogger::log('order.shipment_booked', $order, ['courier' => $courier->code(), 'tracking_number' => $shipment->trackingNumber]);

        return back()->with('status', 'Shipment booked with '.$courier->label().'.');
    }

    /** Staff view of a customer's payment receipt. It lives on the private disk and is only ever served through this route. */
    public function paymentProof(Order $order, int $payment): \Symfony\Component\HttpFoundation\Response
    {
        $p = $order->payments()->whereKey($payment)->firstOrFail();
        abort_unless($p->proof_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->proof_path), 404);

        $isPdf = str_ends_with($p->proof_path, '.pdf');

        return \Illuminate\Support\Facades\Storage::disk('local')->response($p->proof_path, 'payment-proof-'.$order->number.($isPdf ? '.pdf' : '.'.pathinfo($p->proof_path, PATHINFO_EXTENSION)), [
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox", 'Cache-Control' => 'private, no-store',
        ], $isPdf ? 'attachment' : 'inline');
    }

    public function verifyPayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120'], 'amount' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:190']]);

        try {
            $this->payments->verifyManually($order, $request->user(), $data['reference'] ?? null, isset($data['amount']) ? (int) $data['amount'] : null, $data['note'] ?? null);
        } catch (CartException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        \App\Services\OrderMailer::paid($order->fresh());

        return back()->with('status', 'Payment verified.');
    }

    public function rejectPayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        try {
            $this->payments->rejectManually($order, $request->user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment marked as not received.');
    }

    /** Record (not perform) a refund once the money has been returned to the customer. */
    public function recordRefund(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        try {
            $this->payments->recordRefund($order, $request->user(), $data['note'] ?? null);
        } catch (CartException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', 'Refund recorded.');
    }

    /** Printable invoice / packing slip. */
    public function invoice(Order $order): View
    {
        return view('admin.orders.invoice', ['order' => $order->load('items')]);
    }

    /* ---------------- internals ---------------- */

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'payment' => ['nullable', 'in:'.implode(',', array_keys(Order::PAYMENT_STATUSES))],
            'method' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'bail', 'string', 'date'], 'to' => ['nullable', 'bail', 'string', 'date', 'after_or_equal:from'],
        ]);
    }

    private function filtered(array $f): Builder
    {
        $like = isset($f['q']) ? '%'.addcslashes($f['q'], '%_\\').'%' : null;

        return Order::query()
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['payment'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($f['method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('email', 'like', $like)
                ->orWhereHas('shippingAddress', fn ($a) => $a->where('name', 'like', $like)->orWhere('phone', 'like', $like))));
    }

    /** Streams in chunks so a large store never loads every order into memory. */
    private function stream(string $name, Builder $query): StreamedResponse
    {
        $headers = ['Order', 'Date', 'Status', 'Payment status', 'Payment method', 'Shipping method', 'Customer', 'Email', 'Phone', 'Province', 'City', 'Address',
            'Items', 'Subtotal', 'Discount', 'Coupon', 'Shipping', 'Tax included', 'Total', 'Tracking'];

        $rows = (function () use ($query) {
            foreach ($query->with(['items', 'shipment'])->orderBy('id')->lazyById(500) as $o) {
                yield [$o->number, $o->created_at->format('Y-m-d H:i'), $o->status, $o->payment_status, $o->payment_method, $o->shipping_method_name,
                    $o->name, $o->email, $o->phone, $o->province, $o->city, $o->address,
                    $o->items->map(fn ($i) => $i->quantity.'× '.$i->name.($i->options ? " ({$i->options})" : ''))->implode('; '),
                    $o->subtotal, $o->discount, $o->coupon_code, $o->shipping_cost, $o->tax_amount, $o->total, $o->tracking_number];
            }
        })();

        return Csv::download($name, $headers, $rows);
    }
}
