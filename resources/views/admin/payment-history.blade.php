@extends('admin.layout')
@section('title', 'Payment history')

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<h1 class="!text-2xl">Payment history</h1>
<p class="help mt-1 max-w-2xl">Every step of every payment, newest first: payment started, provider notices, bank transfers reported, staff decisions and refunds recorded. Rows are never edited or deleted.</p>

<div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mt-4">
    @foreach(['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $l)
        <div class="stat"><span>{{ $l }}</span><b>{{ number_format($totals[$k]->n ?? 0) }}</b><small class="text-grey-text text-xs">{{ $cur }} {{ number_format((int) ($totals[$k]->total ?? 0)) }} in records</small></div>
    @endforeach
</div>

<form method="GET" class="flex flex-wrap gap-3 items-end mt-4" role="search">
    <div class="field"><label class="label text-xs" for="q">Search</label><input class="input" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Order number or reference"></div>
    <div class="field"><label class="label text-xs" for="provider">Method</label>
        <select class="select" id="provider" name="provider"><option value="">All</option>@foreach(['cod' => 'Cash on delivery', 'bank_transfer' => 'Bank transfer', 'card' => 'Card'] as $k => $l)<option value="{{ $k }}" @selected(($filters['provider'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label class="label text-xs" for="status">Status</label>
        <select class="select" id="status" name="status"><option value="">All</option>@foreach(['pending', 'paid', 'failed', 'refunded'] as $k)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ ucfirst($k) }}</option>@endforeach</select></div>
    <button class="btn btn--sm !min-h-[48px]">Filter</button>
</form>

<div class="card !p-0 mt-4 overflow-x-auto">
    <table class="table"><thead><tr><th>When</th><th>Order</th><th>Method</th><th>Event</th><th>Status</th><th>Reference</th><th class="num">Amount</th><th>By</th></tr></thead><tbody>
        @forelse($rows as $t)
            <tr>
                <td>{{ $t->created_at->format('j M Y, g:i a') }}</td>
                <td>@if($t->order)<a class="link" href="{{ route('admin.orders.show', $t->order) }}">{{ $t->order->number }}</a>@else - @endif</td>
                <td>{{ ucwords(str_replace('_', ' ', $t->provider)) }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $t->type)) }}</td>
                <td><span class="badge {{ $t->status === 'paid' ? 'badge--success' : ($t->status === 'failed' ? 'badge--danger' : 'badge--ivory') }}">{{ ucfirst($t->status) }}</span></td>
                <td>{{ $t->reference ?: '-' }}</td>
                <td class="num">{{ $cur }} {{ number_format($t->amount) }}</td>
                <td>{{ $t->user?->name ?: 'System' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center py-8 muted">No payment records yet.</td></tr>
        @endforelse
    </tbody></table>
</div>
<div class="mt-4">{{ $rows->links() }}</div>
@endsection
