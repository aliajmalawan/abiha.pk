@extends('admin.layout')
@section('title', 'Orders')

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="flex flex-wrap items-center justify-between gap-3"><h1 class="!text-2xl !mb-0">Orders</h1>
    <a href="{{ route('admin.orders.export', array_filter($filters)) }}" class="btn btn--sm btn--outline">Export CSV</a></div>

<div class="flex flex-wrap gap-2 my-4 text-sm">
    <a href="{{ route('admin.orders.index') }}" class="badge {{ empty($filters['status']) ? '' : 'badge--ivory' }} no-underline">All</a>
    @foreach(\App\Models\Order::STATUSES as $k => $l)
        <a href="{{ route('admin.orders.index', ['status' => $k]) }}" class="badge {{ ($filters['status'] ?? '') === $k ? '' : 'badge--ivory' }} no-underline">{{ $l }} ({{ $counts[$k] ?? 0 }})</a>
    @endforeach
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2" role="search">
    @if(! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
    <input name="q" value="{{ $filters['q'] ?? '' }}" class="input !min-h-[40px] max-w-xs" placeholder="Order no., email, name or phone" aria-label="Search orders">
    <select name="payment" class="select !min-h-[40px] !w-auto" aria-label="Payment status"><option value="">Any payment</option>@foreach(\App\Models\Order::PAYMENT_STATUSES as $k => $l)<option value="{{ $k }}" @selected(($filters['payment'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="method" class="select !min-h-[40px] !w-auto" aria-label="Method"><option value="">Any method</option>@foreach(['cod' => 'Cash on Delivery', 'bank_transfer' => 'Bank Transfer'] as $k => $l)<option value="{{ $k }}" @selected(($filters['method'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="input !min-h-[40px] !w-auto" aria-label="From date">
    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="input !min-h-[40px] !w-auto" aria-label="To date">
    <button class="btn btn--sm">Filter</button>
</form>

<div class="card !p-0 overflow-x-auto">
    <table class="table">
        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Payment</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse($orders as $o)
            <tr>
                <td><a class="link" href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a><br><span class="text-xs text-grey-text">{{ $o->items_count }} {{ Str::plural('line', $o->items_count) }}</span></td>
                <td class="whitespace-nowrap">{{ $o->created_at->format('j M Y, g:i a') }}</td>
                <td>{{ $o->name }}<br><span class="text-xs text-grey-text">{{ $o->email }}</span></td>
                <td>{{ ucwords(str_replace('_', ' ', $o->payment_method)) }}<br><span class="badge {{ $o->payment_status === 'paid' ? 'badge--success' : ($o->payment_status === 'awaiting_verification' ? 'badge--warning' : 'badge--ivory') }}">{{ $o->payment_status_label }}</span></td>
                <td><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span></td>
                <td class="num font-semibold">{{ $cur }} {{ number_format($o->total) }}</td>
            </tr>
        @empty<tr><td colspan="6" class="text-center py-10 muted">No orders found.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $orders->links() }}</div>
@endsection
