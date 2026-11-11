@extends('admin.layout')
@section('title', 'Order '.$order->number)

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<a href="{{ route('admin.orders.index') }}" class="link text-sm inline-flex items-center gap-1 mb-4"><x-icon name="chevron-left" :size="14" /> Orders</a>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Order {{ $order->number }}</h1>
    <div class="flex items-center gap-2"><span class="badge badge--{{ $order->status_tone }}">{{ $order->status_label }}</span><span class="badge badge--ivory">{{ $order->payment_status_label }}</span>
        <a href="{{ route('admin.orders.invoice', $order) }}" target="_blank" class="btn btn--sm btn--outline">Print invoice</a></div>
</div>
<p class="text-sm text-grey-text mt-1">Placed {{ $order->created_at->format('j M Y, g:i a') }} · {{ ucwords(str_replace('_', ' ', $order->payment_method)) }} · {{ $order->shipping_method_name }}</p>

<div class="grid gap-6 lg:grid-cols-[1fr_360px] mt-6 items-start">
    <div class="space-y-6">
        <section class="card !p-0 overflow-x-auto">
            <table class="table"><thead><tr><th>Item</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead><tbody>
                @foreach($order->items as $i)
                    <tr><td>{{ $i->name }} @if($i->options)<br><span class="text-xs text-grey-text">{{ $i->options }}</span>@endif</td><td class="text-grey-text">{{ $i->sku }}</td>
                        <td class="num">{{ number_format($i->unit_price) }}</td><td class="num">{{ $i->quantity }}</td><td class="num">{{ number_format($i->total) }}</td></tr>
                @endforeach
            </tbody></table>
            <dl class="p-4 ml-auto max-w-xs text-sm space-y-1">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $cur }} {{ number_format($order->subtotal) }}</dd></div>
                @if($order->discount)<div class="flex justify-between text-success"><dt>Discount @if($order->coupon_code)({{ $order->coupon_code }})@endif</dt><dd>− {{ $cur }} {{ number_format($order->discount) }}</dd></div>@endif
                <div class="flex justify-between"><dt>Shipping</dt><dd>{{ $cur }} {{ number_format($order->shipping_cost) }}</dd></div>
                @if($order->tax_amount)<div class="flex justify-between text-grey-text"><dt>Includes {{ $order->tax_label ?? 'tax' }} ({{ rtrim(rtrim(number_format($order->tax_rate, 2), '0'), '.') }}%)</dt><dd>{{ $cur }} {{ number_format($order->tax_amount) }}</dd></div>@endif
                <div class="flex justify-between border-t border-line pt-2 font-bold font-display text-base"><dt>Total</dt><dd>{{ $cur }} {{ number_format($order->total) }}</dd></div>
            </dl>
        </section>

        <section class="card"><h2 class="!text-lg">History</h2>
            <ol class="space-y-3 text-sm border-l-2 border-line pl-4">
                @foreach($order->history as $h)
                    <li><strong>{{ \App\Models\Order::STATUSES[$h->status] ?? $h->status }}</strong> <span class="text-grey-text">· {{ $h->created_at->format('j M Y, g:i a') }}@if($h->user) · {{ $h->user->name }}@endif</span>@if($h->note)<br><span class="text-grey-text">{{ $h->note }}</span>@endif</li>
                @endforeach
            </ol></section>
    </div>

    <aside class="space-y-6">
        <section class="card text-sm"><h2 class="!text-lg">Customer</h2>
            <p>{{ $order->name }}<br>{{ $order->phone }}<br><a class="link" href="mailto:{{ $order->email }}">{{ $order->email }}</a>@if($order->user)<br><a class="link" href="{{ route('admin.customers.show', $order->user->id) }}">View account</a>@else<br><span class="text-grey-text">Guest checkout</span>@endif</p>
            <h3 class="eyebrow mt-4 mb-1">Ship to</h3>
            <address class="not-italic">{{ $order->address }}<br>{{ $order->city }}, {{ $order->province }} {{ $order->postal_code }}</address>
            @if($order->notes)<h3 class="eyebrow mt-4 mb-1">Customer note</h3><p>{{ $order->notes }}</p>@endif
        </section>

        @can('orders.manage')
        <section class="card space-y-3"><h2 class="!text-lg">Process order</h2>
            @if($nextStatuses)
                <form method="POST" action="{{ route('admin.orders.status', $order) }}" class="space-y-2">@csrf @method('PATCH')
                    <label class="label" for="status">Move to</label>
                    <select class="select" id="status" name="status">@foreach($nextStatuses as $s)<option value="{{ $s }}">{{ \App\Models\Order::STATUSES[$s] }}</option>@endforeach</select>
                    <input class="input" name="note" placeholder="Note (optional)" maxlength="190" aria-label="Note">
                    @if(in_array('returned', $nextStatuses))<label class="check"><input type="checkbox" name="restock" value="1"> If marked Returned: put the items back in stock</label>@endif
                    <p class="help">Cancelling returns the items to stock and frees any coupon use. Returned orders only restock if you tick the box (inspect goods first).</p>
                    <button class="btn btn--sm btn--block">Update status</button>
                </form>
            @else<p class="muted text-sm">This order is {{ strtolower($order->status_label) }} and cannot change further.</p>@endif
            <form method="POST" action="{{ route('admin.orders.tracking', $order) }}" class="space-y-2 pt-3 border-t border-line">@csrf @method('PATCH')
                <h3 class="font-semibold text-sm">Shipping</h3>
                <p class="help">Hand the parcel to your courier, then enter their details here. Customers see exactly what you type.</p>
                @if($order->shipping_eta)<p class="text-xs text-grey-text">Estimate shown to the customer: {{ $order->shipping_eta }}</p>@endif
                <label class="label" for="cr">Courier</label><input class="input" id="cr" name="courier" value="{{ old('courier', $order->courier) }}" maxlength="80">
                <label class="label" for="tn">Tracking number</label><input class="input" id="tn" name="tracking_number" value="{{ old('tracking_number', $order->tracking_number) }}" maxlength="120">
                <label class="label" for="tu">Tracking link</label><input class="input" id="tu" type="url" name="tracking_url" value="{{ old('tracking_url', $order->tracking_url) }}" placeholder="https://">
                @error('tracking_url')<span class="error-text">{{ $message }}</span>@enderror
                <button class="btn btn--sm btn--outline btn--block">Save shipping details</button>
            </form>
            @if($order->shipment && $order->shipment->events->isNotEmpty())
                <details class="text-xs pt-2"><summary class="cursor-pointer text-grey-text">Shipment timeline ({{ $order->shipment->events->count() }})</summary>
                    <ol class="mt-2 space-y-1">@foreach($order->shipment->events as $e)<li>{{ $e->occurred_at->format('j M, g:i a') }} · <strong>{{ ucfirst(str_replace('_', ' ', $e->status)) }}</strong>@if($e->description) · {{ $e->description }}@endif · {{ $e->user?->name ?? ucfirst($e->source) }}</li>@endforeach</ol></details>
            @endif
            @if($couriers->isNotEmpty() && ! $order->tracking_number && in_array($order->status, ['confirmed', 'processing'], true))
                <form method="POST" action="{{ route('admin.orders.shipment', $order) }}" class="space-y-2 pt-3 border-t border-line">@csrf
                    <label class="label" for="bk">Book with a connected courier</label>
                    <select class="select" id="bk" name="courier">@foreach($couriers as $c)<option value="{{ $c->code() }}">{{ $c->label() }}</option>@endforeach</select>
                    @error('shipment')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    <button class="btn btn--sm btn--block" data-confirm="Book this shipment now?">Book shipment</button>
                </form>
            @endif
        </section>
        @endcan

        <section class="card space-y-3"><h2 class="!text-lg">Payment</h2>
            <p class="text-sm">{{ ucwords(str_replace('_', ' ', $order->payment_method)) }}: <strong>{{ $order->payment_status_label }}</strong></p>
            @foreach($order->payments as $p)
                <p class="text-xs text-grey-text">{{ ucfirst($p->status) }} · {{ $cur }} {{ number_format($p->amount) }}@if($p->reference) · Ref: {{ $p->reference }}@endif @if($p->proof_path) · <a class="link" href="{{ route('admin.orders.payment.proof', [$order, $p]) }}" target="_blank" rel="noopener">View receipt</a> (uploaded {{ $p->proof_submitted_at?->format('j M, g:i a') }})@endif @if($p->verifier) · verified by {{ $p->verifier->name }} {{ $p->verified_at?->format('j M, g:i a') }}@endif</p>
            @endforeach
            @if($order->paymentTransactions->isNotEmpty())
                <details class="text-xs"><summary class="cursor-pointer text-grey-text">Payment history ({{ $order->paymentTransactions->count() }})</summary>
                    <ol class="mt-2 space-y-1">@foreach($order->paymentTransactions as $t)<li>{{ $t->created_at->format('j M, g:i a') }} · {{ ucfirst(str_replace('_', ' ', $t->type)) }} · <strong>{{ ucfirst($t->status) }}</strong>@if($t->reference) · {{ $t->reference }}@endif · {{ $t->user?->name ?? 'System' }}</li>@endforeach</ol></details>
            @endif
            @can('payments.verify')
                @if($canVerify)
                    <form method="POST" action="{{ route('admin.orders.payment.verify', $order) }}" class="space-y-2 pt-3 border-t border-line">@csrf
                        <p class="help">Confirm only after the money is really received (bank statement checked, or cash collected). The amount must equal the order total.</p>
                        <input class="input" name="reference" placeholder="Bank reference / receipt no." maxlength="120" aria-label="Reference">
                        <input class="input" type="number" name="amount" min="0" placeholder="Amount received (default {{ number_format($order->total) }})" aria-label="Amount received">
                        <input class="input" name="note" placeholder="Note (optional)" maxlength="190" aria-label="Note">
                        <button class="btn btn--sm btn--gold btn--block" data-confirm="Mark this order as PAID?">Mark as paid</button>
                    </form>
                    <form method="POST" action="{{ route('admin.orders.payment.reject', $order) }}" class="space-y-2">@csrf
                        <input class="input" name="note" placeholder="Why not received?" maxlength="190" aria-label="Reason">
                        <button class="btn btn--sm btn--outline btn--block">Payment not received</button>
                    </form>
                @endif
                @if($canRefund)
                    <form method="POST" action="{{ route('admin.orders.payment.refund', $order) }}" class="space-y-2 pt-3 border-t border-line">@csrf
                        <p class="help"><strong>Refund the customer first</strong> (bank transfer, wallet or provider dashboard). This button only records that it was done: it does not send any money.</p>
                        <input class="input" name="note" placeholder="Reference / note (optional)" maxlength="190" aria-label="Refund note">
                        <button class="btn btn--sm btn--outline btn--block" data-confirm="Record that this order was refunded?">Record refund</button>
                    </form>
                @endif
                @if($order->payment_status !== 'paid' && ! in_array($order->payment_method, ['bank_transfer', 'cod']))
                    <p class="help">Online payments are confirmed automatically by the payment provider.</p>
                @endif
            @endcan
        </section>
    </aside>
</div>
@endsection
