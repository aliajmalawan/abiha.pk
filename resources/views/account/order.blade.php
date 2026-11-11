@extends('account.layout')
@section('title', 'Order '.$order->number.'')
@section('heading', 'Order details')

@section('account')
<a href="{{ route('account.orders') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> All orders</a>

<div class="card">
    @include('partials.order-tracking', ['order' => $order])
    <div class="grid gap-6 sm:grid-cols-2 text-sm mt-8 pt-6 border-t border-line">
        <div><h3 class="eyebrow mb-2">Delivery address</h3><p>{{ $order->name }}<br>{{ $order->address }}<br>{{ $order->city }}, {{ $order->province }} {{ $order->postal_code }}<br>{{ $order->phone }}</p></div>
        <div><h3 class="eyebrow mb-2">Payment</h3><p>{{ $order->payment_method === 'cod' ? 'Cash on Delivery' : ucwords(str_replace('_', ' ', $order->payment_method)) }}<br><span class="text-grey-text">{{ $order->payment_status_label }}</span></p></div>
    </div>
    @can('cancel', $order)
        <form method="POST" action="{{ route('account.orders.cancel', $order) }}" class="mt-6" x-data @submit="$store.confirm.ask($event, { title: 'Cancel this order?', message: 'The items go back on the shelf and this cannot be undone.', label: 'Yes, cancel order' })">@csrf<button class="btn btn--outline btn--sm">Cancel order</button></form>
    @endcan
</div>
@endsection
