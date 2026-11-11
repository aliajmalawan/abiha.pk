@extends('account.layout')
@section('title', 'Order history')
@section('heading', 'Order history')

@section('account')
@php $cur = config('abiha.currency.symbol'); @endphp
@forelse($orders as $o)
    <a href="{{ route('account.orders.show', $o) }}" class="card card--hover no-underline flex flex-wrap items-center justify-between gap-3 mb-3">
        <span><strong class="font-display">{{ $o->number }}</strong><br><span class="text-sm text-grey-text">{{ $o->created_at->format('j M Y') }} · {{ $o->items_count }} {{ Str::plural('line', $o->items_count) }}</span></span>
        <span class="flex items-center gap-3"><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span><strong>{{ $cur }} {{ number_format($o->total) }}</strong></span>
    </a>
@empty
    <x-empty-state icon="package" title="No orders yet" text="When you place an order it will appear here so you can follow it." action="Start shopping" :href="route('shop')" />
@endforelse
<div class="mt-6">{{ $orders->links() }}</div>
@endsection
