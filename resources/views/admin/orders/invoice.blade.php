<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Invoice {{ $order->number }}</title>
@vite(['resources/css/app.css'])</head>
<body class="bg-white p-8 max-w-3xl mx-auto">
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="flex justify-between items-start">
    <x-logo :width="130" />
    <div class="text-right text-sm"><h1 class="!text-xl !mb-1">Invoice</h1><p>{{ $order->number }}<br>{{ $order->created_at->format('j M Y') }}</p></div>
</div>
<div class="grid grid-cols-2 gap-6 my-8 text-sm">
    <div><h2 class="eyebrow mb-1">Bill / ship to</h2><p>{{ $order->name }}<br>{{ $order->address }}<br>{{ $order->city }}, {{ $order->province }} {{ $order->postal_code }}<br>{{ $order->phone }}<br>{{ $order->email }}</p></div>
    <div><h2 class="eyebrow mb-1">Order</h2><p>Payment: {{ ucwords(str_replace('_', ' ', $order->payment_method)) }} ({{ $order->payment_status_label }})<br>Shipping: {{ $order->shipping_method_name }}</p></div>
</div>
<table class="table"><thead><tr><th>Item</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead><tbody>
    @foreach($order->items as $i)<tr><td>{{ $i->name }} @if($i->options)<span class="text-xs text-grey-text">({{ $i->options }})</span>@endif</td><td>{{ $i->sku }}</td><td class="num">{{ number_format($i->unit_price) }}</td><td class="num">{{ $i->quantity }}</td><td class="num">{{ number_format($i->total) }}</td></tr>@endforeach
</tbody></table>
<dl class="mt-4 ml-auto max-w-xs text-sm space-y-1">
    <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $cur }} {{ number_format($order->subtotal) }}</dd></div>
    @if($order->discount)<div class="flex justify-between"><dt>Discount</dt><dd>− {{ $cur }} {{ number_format($order->discount) }}</dd></div>@endif
    <div class="flex justify-between"><dt>Shipping</dt><dd>{{ $cur }} {{ number_format($order->shipping_cost) }}</dd></div>
    @if($order->tax_amount)<div class="flex justify-between text-grey-text"><dt>Includes {{ $order->tax_label ?? 'tax' }} ({{ rtrim(rtrim(number_format($order->tax_rate, 2), '0'), '.') }}%)</dt><dd>{{ $cur }} {{ number_format($order->tax_amount) }}</dd></div>@endif
    <div class="flex justify-between border-t border-ink pt-2 font-bold text-base"><dt>Total</dt><dd>{{ $cur }} {{ number_format($order->total) }}</dd></div>
</dl>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">window.addEventListener('load', () => window.print());</script>
</body></html>
