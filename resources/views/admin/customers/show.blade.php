@extends('admin.layout')
@section('title', $customer->name)

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<a href="{{ route('admin.customers.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> Customers</a>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">{{ $customer->name }}</h1>
    <form method="POST" action="{{ route('admin.customers.active', $customer->id) }}" data-confirm="{{ $customer->is_active ? 'Block this customer from signing in?' : 'Allow this customer to sign in again?' }}">@csrf @method('PATCH')
        <button class="btn btn--sm {{ $customer->is_active ? 'btn--outline' : '' }}">{{ $customer->is_active ? 'Block sign-in' : 'Unblock' }}</button></form>
</div>
<div class="grid gap-4 sm:grid-cols-3 mt-4">
    <div class="stat"><span>Orders</span><b>{{ $orderCount }}</b></div>
    <div class="stat"><span>Lifetime spend</span><b>{{ $cur }} {{ number_format($spent) }}</b></div>
    <div class="stat"><span>Last sign-in</span><b class="!text-base">{{ $customer->last_login_at?->format('j M Y') ?? 'Never' }}</b></div>
</div>
<div class="grid gap-6 lg:grid-cols-[1fr_340px] mt-6 items-start">
    <section class="card !p-0 overflow-x-auto"><h2 class="!text-lg p-4 !mb-0">Orders</h2>
        <table class="table"><thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
            @forelse($orders as $o)<tr><td><a class="link" href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td><td>{{ $o->created_at->format('j M Y') }}</td><td><span class="badge badge--{{ $o->status_tone }}">{{ $o->status_label }}</span></td><td class="num">{{ $cur }} {{ number_format($o->total) }}</td></tr>
            @empty<tr><td colspan="4" class="text-center py-8 muted">No orders.</td></tr>@endforelse
        </tbody></table></section>
    <aside class="space-y-6 text-sm">
        <section class="card"><h2 class="!text-lg">Contact</h2><p>{{ $customer->email }}<br>{{ $customer->phone ?? 'No phone' }}<br>Joined {{ $customer->created_at->format('j M Y') }}</p></section>
        <section class="card"><h2 class="!text-lg">Addresses</h2>
            @forelse($customer->addresses as $a)<address class="not-italic mb-3">{{ $a->name }} @if($a->is_default)<span class="badge badge--gold">Default</span>@endif<br>{{ $a->address }}, {{ $a->city }}, {{ $a->province }}<br>{{ $a->phone }}</address>@empty<p class="muted">None saved.</p>@endforelse</section>
    </aside>
</div>
@endsection
