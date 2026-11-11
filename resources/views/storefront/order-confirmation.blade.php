@extends('layouts.app')
@section('title', 'Order confirmed')
@section('noindex', true)

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="container py-12 lg:py-16 max-w-3xl">
    <div class="text-center">
        <span class="inline-grid place-content-center size-16 rounded-full bg-ivory text-ink"><x-icon name="check" :size="30" /></span>
        <h1 class="!text-3xl lg:!text-4xl mt-4">Thank you, {{ \Illuminate\Support\Str::before($order->name, ' ') }}.</h1>
        <p class="lead mx-auto">Your order <strong class="text-ink">{{ $order->number }}</strong> has been received.</p>
    </div>

    @error('order')<p class="alert alert--danger mt-6" role="alert">{{ $message }}</p>@enderror
    @if($order->payment_method === 'bank_transfer')
        <div class="card !bg-ivory !border-transparent mt-8">
            <h2 class="!text-lg">Complete your bank transfer</h2>
            <p class="text-sm">Please transfer <strong>{{ $cur }} {{ number_format($order->total) }}</strong> and quote order number <strong>{{ $order->number }}</strong> as the reference. We start processing once the payment is verified.</p>
            @if($bankInstructions)<pre class="mt-3 whitespace-pre-wrap font-body text-sm bg-white border border-line rounded p-4">{{ $bankInstructions }}</pre>@endif
            @if($order->payment_status === 'unpaid' || $order->payment_status === 'failed')
                <form method="POST" action="{{ route('order.payment-reference', $order) }}" enctype="multipart/form-data" class="mt-4 space-y-3">
                    @csrf <input type="hidden" name="token" value="{{ request('token') }}">
                    <div class="field">
                        <label class="label" for="ref">Already transferred? Enter your bank reference / transaction ID</label>
                        <input class="input" id="ref" name="reference" minlength="3" maxlength="120" value="{{ old('reference') }}" @error('reference') aria-invalid="true" @enderror>
                    </div>
                    <div class="field">
                        <label class="label" for="proof">Or upload your payment receipt (JPG, PNG or PDF, up to 4 MB)</label>
                        <input class="input" id="proof" type="file" name="proof" accept="image/jpeg,image/png,application/pdf" @error('proof') aria-invalid="true" @enderror>
                        @error('proof')<p class="error-text">{{ $message }}</p>@enderror
                    </div>
                    @error('reference')<p class="error-text" role="alert">{{ $message }}</p>@enderror
                    <button class="btn btn--sm">Submit payment details</button>
                    <p class="help">You can send the reference, the receipt, or both. This does not confirm your payment: our team verifies it against our bank account first.</p>
                </form>
            @elseif($order->payment_status === 'awaiting_verification')
                <p class="alert alert--info mt-4">Thanks. We have your transfer reference and are verifying the payment.</p>
            @endif
        </div>
    @elseif($order->payment_method === 'card')
        <div class="card !bg-ivory !border-transparent mt-8">
            <h2 class="!text-lg">Card payment</h2>
            @if($order->payment_status === 'paid')
                <p class="alert alert--success">Payment received. Thank you.</p>
            @elseif($order->payment_status === 'pending')
                <p class="alert alert--info">Your payment is being processed by our payment provider. This page does not mark it paid: we confirm it once the provider tells us it succeeded.</p>
            @elseif($order->payment_status === 'failed')
                <p class="alert alert--danger">Your card payment did not go through. Your order is still saved, so you can try again.</p>
            @else
                <p class="text-sm">Your order is placed but not paid yet. Complete the card payment on our payment provider's secure page.</p>
            @endif
            @if(in_array($order->payment_status, ['unpaid', 'pending', 'failed'], true) && ! in_array($order->status, ['cancelled', 'returned'], true) && app(\App\Services\Payments\CardGateway::class)->isAvailable())
                <form method="POST" action="{{ route('order.pay', $order) }}" class="mt-4">@csrf <input type="hidden" name="token" value="{{ request('token') }}"><button class="btn btn--sm">{{ $order->payment_status === 'failed' ? 'Try card payment again' : 'Pay by card' }}</button></form>
            @endif
        </div>
    @elseif($order->payment_method === 'cod')
        <div class="alert alert--info mt-8">You chose <strong>Cash on Delivery</strong>. Please keep {{ $cur }} {{ number_format($order->total) }} ready when your order arrives.</div>
    @endif

    <div class="card mt-8 space-y-6">
        <div class="grid gap-6 sm:grid-cols-2 text-sm">
            <div><h2 class="eyebrow mb-2">Delivering to</h2><p>{{ $order->name }}<br>{{ $order->address }}<br>{{ $order->city }}, {{ $order->province }} {{ $order->postal_code }}<br>{{ $order->phone }}</p></div>
            <div><h2 class="eyebrow mb-2">Status</h2><p><span class="badge badge--{{ $order->status_tone }}">{{ $order->status_label }}</span> <span class="badge badge--ivory">{{ $order->payment_status_label }}</span></p><p class="mt-2 text-grey-text">Placed {{ $order->created_at->format('j M Y, g:i a') }}</p>@if($order->shipping_eta)<p class="mt-1">Estimated delivery: <strong>{{ $order->shipping_eta }}</strong> <span class="text-grey-text">({{ $order->shipping_method_name }}, an estimate)</span></p>@endif</div>
        </div>
        <ul class="divide-y divide-line border-y border-line">
            @foreach($order->items as $item)
                <li class="py-3 flex justify-between gap-4 text-sm"><span>{{ $item->name }} @if($item->options)<span class="text-grey-text">· {{ $item->options }}</span>@endif × {{ $item->quantity }}</span><span class="font-semibold">{{ $cur }} {{ number_format($item->total) }}</span></li>
            @endforeach
        </ul>
        <dl class="space-y-1 text-sm ml-auto max-w-xs">
            <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $cur }} {{ number_format($order->subtotal) }}</dd></div>
            @if($order->discount)<div class="flex justify-between text-success"><dt>Discount ({{ $order->coupon_code }})</dt><dd>− {{ $cur }} {{ number_format($order->discount) }}</dd></div>@endif
            <div class="flex justify-between"><dt>Shipping</dt><dd>{{ $order->shipping_cost ? $cur.' '.number_format($order->shipping_cost) : 'Free' }}</dd></div>
            @if($order->tax_amount)<div class="flex justify-between text-grey-text"><dt>Includes {{ $order->tax_label ?? 'tax' }}</dt><dd>{{ $cur }} {{ number_format($order->tax_amount) }}</dd></div>@endif
            <div class="flex justify-between border-t border-line pt-2 text-base font-bold font-display"><dt>Total</dt><dd>{{ $cur }} {{ number_format($order->total) }}</dd></div>
        </dl>
    </div>

    <p class="text-sm text-grey-text text-center mt-6">Save your order number. You can follow your order any time from
        <a class="link" href="{{ route('track') }}">Track order</a>@auth or <a class="link" href="{{ route('account.orders.show', $order) }}">your account</a>@endauth.</p>
    <div class="text-center mt-6"><a href="{{ route('shop') }}" class="btn btn--outline">Continue shopping</a></div>
</div>
@endsection
