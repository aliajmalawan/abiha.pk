@extends('admin.layout')
@section('title', 'Dashboard')

@section('content')
@php
    $cur = config('abiha.currency.symbol');
    $monthItems = $months->map(fn ($m) => ['label' => $m->label, 'title_label' => $m->label.' '.$m->year, 'value' => $m->revenue, 'title' => $m->label.' '.$m->year.': '.$cur.' '.number_format($m->revenue).' from '.$m->orders.' orders'])->all();
    $dayMap = $daily->keyBy('period');
    $dayItems = collect(range(29, 0))->map(function ($ago) use ($dayMap, $cur) {
        $d = now()->subDays($ago); $row = $dayMap->get($d->format('Y-m-d'));
        return ['label' => $d->format('j M'), 'value' => $row->revenue ?? 0, 'title' => $d->format('D j M').': '.$cur.' '.number_format($row->revenue ?? 0).' from '.($row->orders ?? 0).' orders'];
    })->all();
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Dashboard</h1>
    @can('reports.view')<a href="{{ route('admin.reports.index') }}" class="btn btn--sm btn--outline">All reports</a>@endcan
</div>

<div class="grid gap-4 grid-cols-2 lg:grid-cols-4 mt-4">
    <div class="stat"><span>Total revenue</span><b>{{ $cur }} {{ number_format($stats['revenue']) }}</b><small class="text-grey-text text-xs">{{ $cur }} {{ number_format($stats['collected']) }} collected</small></div>
    <div class="stat"><span>Total sales</span><b>{{ number_format($stats['units_sold']) }}</b><small class="text-grey-text text-xs">items sold</small></div>
    <div class="stat"><span>Total orders</span><b>{{ number_format($stats['orders']) }}</b><small class="text-grey-text text-xs">{{ $stats['today_orders'] }} today · {{ $cur }} {{ number_format($stats['today_sales']) }}</small></div>
    <div class="stat"><span>Average order</span><b>{{ $cur }} {{ number_format($stats['avg_order']) }}</b><small class="text-grey-text text-xs">per order</small></div>
    <div class="stat"><span>Pending orders</span><b>{{ number_format($stats['pending']) }}</b><small class="text-grey-text text-xs">{{ $stats['to_verify'] }} payments to verify</small></div>
    <div class="stat"><span>Completed orders</span><b>{{ number_format($stats['completed']) }}</b><small class="text-grey-text text-xs">delivered</small></div>
    <div class="stat"><span>Customers</span><b>{{ number_format($stats['customers']) }}</b></div>
    <div class="stat"><span>Products</span><b>{{ number_format($stats['products']) }}</b><small class="text-grey-text text-xs">{{ $stats['products_active'] }} visible</small></div>
    <a href="{{ route('admin.inventory.index', ['status' => 'low']) }}" class="stat no-underline"><span>Low-stock products</span><b class="{{ $stats['low_stock'] ? 'text-warning' : '' }}">{{ $stats['low_stock'] }}</b><small class="text-grey-text text-xs">{{ $stats['out_of_stock'] }} out of stock</small></a>
    <a href="{{ route('admin.inventory.index', ['status' => 'out']) }}" class="stat no-underline"><span>Out of stock</span><b class="{{ $stats['out_of_stock'] ? 'text-danger' : '' }}">{{ $stats['out_of_stock'] }}</b><small class="text-grey-text text-xs">visible but sold out</small></a>
    <div class="stat"><span>Today</span><b>{{ $cur }} {{ number_format($stats['today_sales']) }}</b><small class="text-grey-text text-xs">{{ $stats['today_orders'] }} orders</small></div>
    <div class="stat"><span>Last 30 days</span><b>{{ $cur }} {{ number_format($last30) }}</b>
        <small class="text-xs {{ ($trend ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">@if($trend === null)No earlier period to compare @else{{ $trend >= 0 ? '▲' : '▼' }} {{ abs($trend) }}% vs the previous 30 days @endif</small></div>
</div>
<p class="help mt-2">Revenue excludes cancelled and returned orders. "Collected" is the part already marked paid.</p>

<div class="grid gap-6 xl:grid-cols-2 mt-6">
    <section class="card"><h2 class="!text-lg">Monthly revenue <span class="text-grey-text text-sm font-normal">(last 12 months)</span></h2>
        @include('admin.partials.bar-chart', ['items' => $monthItems, 'caption' => 'Monthly revenue for the last 12 months'])</section>
    <section class="card"><h2 class="!text-lg">Daily sales <span class="text-grey-text text-sm font-normal">(last 30 days)</span></h2>
        @include('admin.partials.bar-chart', ['items' => $dayItems, 'caption' => 'Daily revenue for the last 30 days'])</section>
</div>

<h2 class="!text-lg mt-8">Needs attention</h2>
<div class="flex flex-wrap gap-3 mt-2 text-sm">
    @can('orders.view')<a class="badge badge--warning no-underline" href="{{ route('admin.orders.index', ['status' => 'pending']) }}">{{ $stats['pending'] }} pending orders</a>
        <a class="badge badge--warning no-underline" href="{{ route('admin.orders.index', ['payment' => 'awaiting_verification']) }}">{{ $stats['to_verify'] }} payments to verify</a>@endcan
    @can('messages.manage')<a class="badge badge--ivory no-underline" href="{{ route('admin.messages.index', ['status' => 'new']) }}">{{ $stats['new_messages'] }} new messages</a>@endcan
    @can('reviews.manage')<a class="badge badge--ivory no-underline" href="{{ route('admin.reviews.index') }}">{{ $stats['pending_reviews'] }} reviews to approve</a>@endcan
</div>

<div class="grid gap-6 lg:grid-cols-[2fr_1fr] mt-8">
    @can('orders.view')
    <section class="card !p-0 overflow-x-auto">
        <h2 class="!text-lg p-4 !mb-0">Recent orders</h2>
        <table class="table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
            @forelse($recentOrders as $o)
                <tr><td><a class="link" href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td><td>{{ $o->name }}</td>
                    <td><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span></td><td class="num">{{ $cur }} {{ number_format($o->total) }}</td></tr>
            @empty<tr><td colspan="4" class="text-center py-8 muted">No orders yet.</td></tr>@endforelse
        </tbody></table>
    </section>
    @endcan
    <div class="space-y-6">
        <section class="card"><h2 class="!text-lg">Top sellers <span class="text-grey-text text-sm font-normal">(30 days)</span></h2>
            <ol class="space-y-2 text-sm">@forelse($topProducts as $p)<li class="flex justify-between gap-3"><span class="truncate">{{ $p->name }}</span><span class="text-grey-text shrink-0">{{ $p->units }} sold</span></li>@empty<li class="muted">No sales in this period.</li>@endforelse</ol></section>
        <section class="card"><h2 class="!text-lg">Best sellers <span class="text-grey-text text-sm font-normal">(all time)</span></h2>
            <ol class="space-y-2 text-sm">@forelse($topAllTime as $p)<li class="flex justify-between gap-3"><span class="truncate">{{ $p->name }}</span><span class="text-grey-text shrink-0">{{ $p->units }} sold</span></li>@empty<li class="muted">No sales yet.</li>@endforelse</ol></section>
    </div>
</div>
@endsection
