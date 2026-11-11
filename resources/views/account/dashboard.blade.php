@extends('account.layout')
@section('title', 'My account')
@section('heading', 'Hello, '.\Illuminate\Support\Str::before(auth()->user()->name, ' '))

@section('account')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="grid gap-4 sm:grid-cols-3">
    <a href="{{ route('account.orders') }}" class="card card--hover no-underline"><p class="eyebrow">Orders</p><p class="font-display font-extrabold text-3xl !mb-0">{{ $orderCount }}</p></a>
    <a href="{{ route('wishlist') }}" class="card card--hover no-underline"><p class="eyebrow">Wishlist</p><p class="font-display font-extrabold text-3xl !mb-0">{{ $wishlistCount }}</p></a>
    <a href="{{ route('account.addresses') }}" class="card card--hover no-underline"><p class="eyebrow">Default address</p><p class="text-sm !mb-0">{{ $defaultAddress ? $defaultAddress->city.', '.$defaultAddress->province : 'Not added yet' }}</p></a>
</div>

<h2 class="!text-xl mt-10">Recent orders</h2>
@forelse($recentOrders as $o)
    <a href="{{ route('account.orders.show', $o) }}" class="card card--hover no-underline flex flex-wrap items-center justify-between gap-3 mb-3">
        <span><strong class="font-display">{{ $o->number }}</strong><br><span class="text-sm text-grey-text">{{ $o->created_at->format('j M Y') }} · {{ $o->items->sum('quantity') }} items</span></span>
        <span class="flex items-center gap-3"><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span><strong>{{ $cur }} {{ number_format($o->total) }}</strong></span>
    </a>
@empty
    <p class="muted">You haven't placed an order yet. <a class="link" href="{{ route('shop') }}">Start shopping</a></p>
@endforelse
@endsection
