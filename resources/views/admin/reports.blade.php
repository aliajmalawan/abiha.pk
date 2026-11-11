@extends('admin.layout')
@section('title', 'Reports')

@section('content')
@php
    $cur = config('abiha.currency.symbol');
    $q = array_filter(['type' => $type, 'period' => $period, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => $status]);
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Reports</h1>
    <a href="{{ route('admin.reports.index', $q + ['export' => 'csv']) }}" class="btn btn--sm">Download CSV</a>
</div>

<nav class="flex flex-wrap gap-2 my-4 text-sm" aria-label="Report type">
    @foreach($types as $k => $label)<a href="{{ route('admin.reports.index', ['type' => $k, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="badge {{ $type === $k ? '' : 'badge--ivory' }} no-underline" @if($type === $k) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>

<form method="GET" class="card !p-4 mb-6 flex flex-wrap items-end gap-3">
    <input type="hidden" name="type" value="{{ $type }}">
    @if($type === 'sales')
        <div class="field"><label class="label text-xs" for="period">Group by</label><select class="select !min-h-[40px]" id="period" name="period">@foreach(['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach</select></div>
    @endif
    @if($type === 'orders')
        <div class="field"><label class="label text-xs" for="status">Status</label><select class="select !min-h-[40px]" id="status" name="status"><option value="">Any</option>@foreach(\App\Models\Order::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
    @endif
    <div class="field"><label class="label text-xs" for="from">From</label><input class="input !min-h-[40px]" type="date" id="from" name="from" value="{{ $from->toDateString() }}"></div>
    <div class="field"><label class="label text-xs" for="to">To</label><input class="input !min-h-[40px]" type="date" id="to" name="to" value="{{ $to->toDateString() }}"></div>
    <button class="btn btn--sm !min-h-[40px]">Update</button>
    @error('to')<span class="error-text w-full">{{ $message }}</span>@enderror
</form>
<p class="help mb-4">Revenue counts orders that are not cancelled or returned. "Collected" is the part already marked paid. Dates use Pakistan time.</p>

@if($type === 'sales')
    @php $tot = fn ($k) => $sales->sum($k); @endphp
    @if($sales->isNotEmpty())
        <section class="card mb-6"><h2 class="!text-lg">Revenue by {{ $period }}</h2>
            @include('admin.partials.bar-chart', ['items' => $sales->map(fn ($r) => ['label' => $r->period, 'value' => $r->revenue, 'title' => $r->period.': '.$cur.' '.number_format($r->revenue).' from '.$r->orders.' orders'])->values()->all(), 'caption' => ucfirst($period).'ly revenue'])</section>
    @endif
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>{{ ucfirst($period) }}</th><th class="num">Orders</th><th class="num">Gross sales</th><th class="num">Discounts</th><th class="num">Shipping</th><th class="num">Tax incl.</th><th class="num">Revenue</th><th class="num">Collected</th></tr></thead><tbody>
        @forelse($sales as $r)<tr><td>{{ $r->period }}</td><td class="num">{{ $r->orders }}</td><td class="num">{{ number_format($r->gross) }}</td><td class="num">{{ number_format($r->discount) }}</td><td class="num">{{ number_format($r->shipping) }}</td><td class="num">{{ number_format($r->tax) }}</td><td class="num font-semibold">{{ number_format($r->revenue) }}</td><td class="num">{{ number_format($r->collected) }}</td></tr>
        @empty<tr><td colspan="8" class="text-center py-10 muted">No orders in this period.</td></tr>@endforelse
    </tbody>@if($sales->isNotEmpty())<tfoot><tr class="font-bold"><td>Total</td><td class="num">{{ $tot('orders') }}</td><td class="num">{{ number_format($tot('gross')) }}</td><td class="num">{{ number_format($tot('discount')) }}</td><td class="num">{{ number_format($tot('shipping')) }}</td><td class="num">{{ number_format($tot('tax')) }}</td><td class="num">{{ number_format($tot('revenue')) }}</td><td class="num">{{ number_format($tot('collected')) }}</td></tr></tfoot>@endif</table></div>

@elseif($type === 'products')
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Product</th><th>SKU</th><th class="num">Units sold</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead><tbody>
        @forelse($products as $r)<tr><td>{{ $r->name }}</td><td class="text-grey-text">{{ $r->sku }}</td><td class="num">{{ number_format($r->units) }}</td><td class="num">{{ $r->orders }}</td><td class="num font-semibold">{{ $cur }} {{ number_format($r->revenue) }}</td></tr>
        @empty<tr><td colspan="5" class="text-center py-10 muted">No sales in this period.</td></tr>@endforelse
    </tbody></table></div>

@elseif($type === 'customers')
    <p class="mb-3 text-sm"><strong>{{ $newCustomers }}</strong> new customer accounts were created in this period.</p>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Customer</th><th>Account</th><th class="num">Orders</th><th class="num">Total spent</th><th>Last order</th></tr></thead><tbody>
        @forelse($customers as $r)<tr><td>{{ $r->name ?: '-' }}<br><span class="text-xs text-grey-text">{{ $r->email }}</span></td><td><span class="badge {{ $r->account ? 'badge--success' : 'badge--ivory' }}">{{ $r->account ? 'Registered' : 'Guest' }}</span></td><td class="num">{{ $r->orders }}</td><td class="num font-semibold">{{ $cur }} {{ number_format($r->spent) }}</td><td>{{ \Illuminate\Support\Carbon::parse($r->last_order)->format('j M Y') }}</td></tr>
        @empty<tr><td colspan="5" class="text-center py-10 muted">No orders in this period.</td></tr>@endforelse
    </tbody></table></div>

@elseif($type === 'revenue')
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach(['method' => 'By payment method (counted orders)', 'status' => 'By order status (all orders)'] as $k => $title)
            <section class="card !p-0 overflow-x-auto"><h2 class="!text-lg p-4 !mb-0">{{ $title }}</h2>
                <table class="table"><thead><tr><th>{{ $k === 'method' ? 'Method' : 'Status' }}</th><th class="num">Orders</th><th class="num">Order value</th>@if($k === 'method')<th class="num">Collected</th>@endif</tr></thead><tbody>
                    @forelse($breakdown[$k] as $r)<tr><td>{{ ucwords(str_replace('_', ' ', $r->label)) }}</td><td class="num">{{ $r->orders }}</td><td class="num">{{ number_format($r->revenue) }}</td>@if($k === 'method')<td class="num">{{ number_format($r->collected) }}</td>@endif</tr>
                    @empty<tr><td colspan="4" class="text-center py-8 muted">No orders.</td></tr>@endforelse
                </tbody></table></section>
        @endforeach
    </div>

@else
    <p class="mb-3 text-sm">{{ number_format($ordersTotal) }} orders in this period @if($ordersTotal > 200) (showing the latest 200; the CSV has them all)@endif.</p>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead><tbody>
        @forelse($orders as $o)<tr><td><a class="link" href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td><td>{{ $o->created_at->format('j M Y') }}</td><td>{{ $o->name }}</td><td><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span></td><td>{{ $o->payment_status_label }}</td><td class="num">{{ $cur }} {{ number_format($o->total) }}</td></tr>
        @empty<tr><td colspan="6" class="text-center py-10 muted">No orders in this period.</td></tr>@endforelse
    </tbody></table></div>
@endif
@endsection
