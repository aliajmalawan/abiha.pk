@extends('layouts.app')
@section('title', 'Your bag')
@section('noindex', true)

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="container py-10 lg:py-14">
    <h1 class="!text-3xl lg:!text-4xl">Your bag @if(! $summary->isEmpty())<span class="text-grey-text text-xl font-normal">({{ $summary->count() }})</span>@endif</h1>

    @if($summary->isEmpty())
        <x-empty-state icon="shopping-bag" title="Your bag is empty" text="Add something you love and it will wait for you here." action="Continue shopping" :href="route('shop')" />
    @else
        @foreach($summary->notices() as $n)<div class="alert alert--warning mb-3" role="alert">{{ $n }}</div>@endforeach

        <div class="grid gap-10 lg:grid-cols-[1fr_380px] mt-6">
            <ul class="divide-y divide-line border-y border-line">
                @foreach($summary->lines as $line)
                    @php $p = $line->product; @endphp
                    <li class="py-6 grid grid-cols-[96px_1fr] gap-4 sm:grid-cols-[120px_1fr_auto] sm:gap-6" x-data="cartLine('{{ route('cart.update', $line->key) }}')">
                        <a href="{{ route('product', $p) }}" class="relative block aspect-[4/5] overflow-hidden rounded bg-ivory border border-line">
                            @if($p->imagePath(0))<x-picture :path="$p->imagePath(0)" alt="" sizes="120px" class="size-full object-contain" />@else<x-placeholder :label="false" />@endif
                        </a>
                        <div class="min-w-0">
                            <a href="{{ route('product', $p) }}" class="font-display font-semibold no-underline hover:underline">{{ $p->name }}</a>
                            @if($line->options())<p class="text-sm text-grey-text mt-1">{{ $line->options() }}</p>@endif
                            <p class="text-xs text-grey-text">SKU: {{ $line->sku() }}</p>
                            <p class="price mt-2">{{ $cur }} {{ number_format($line->unitPrice) }} @if($line->comparePrice)<s class="text-sm">{{ $cur }} {{ number_format($line->comparePrice) }}</s>@endif</p>

                            <div class="mt-3 flex flex-wrap items-center gap-4">
                                @if($line->stock > 0)
                                    <div class="inline-flex items-center border border-input rounded-md" role="group" aria-label="Quantity for {{ $p->name }}">
                                        <button type="button" class="size-10 grid place-content-center" @click="set({{ $line->quantity - 1 }})" :disabled="busy" aria-label="Decrease quantity"><x-icon name="minus" :size="14" /></button>
                                        <output class="w-8 text-center text-sm font-semibold">{{ $line->quantity }}</output>
                                        <button type="button" class="size-10 grid place-content-center" @click="set({{ $line->quantity + 1 }})" :disabled="busy || {{ $line->quantity >= min($line->stock, 20) ? 'true' : 'false' }}" aria-label="Increase quantity"><x-icon name="plus" :size="14" /></button>
                                    </div>
                                @endif
                                <form method="POST" action="{{ route('cart.remove', $line->key) }}">@csrf @method('DELETE')
                                    <button class="text-sm underline text-grey-text hover:text-ink inline-flex items-center gap-1"><x-icon name="trash-2" :size="15" /> Remove</button>
                                </form>
                            </div>
                        </div>
                        <p class="price col-start-2 sm:col-start-3 sm:text-right">{{ $cur }} {{ number_format($line->stock > 0 ? $line->total() : 0) }}</p>
                    </li>
                @endforeach
            </ul>

            <aside class="card !bg-ivory !border-transparent h-fit lg:sticky lg:top-28 space-y-5" aria-label="Order summary">
                <h2 class="!text-xl !mb-0">Order summary</h2>

                @if($summary->coupon)
                    <div class="flex items-center justify-between gap-3 rounded-md bg-white border border-line px-3 py-2 text-sm">
                        <span class="inline-flex items-center gap-2"><x-icon name="tag" :size="16" /> <strong>{{ $summary->coupon->code }}</strong></span>
                        <form method="POST" action="{{ route('cart.coupon.remove') }}">@csrf @method('DELETE')<button class="underline text-grey-text">Remove</button></form>
                    </div>
                @else
                    <form method="POST" action="{{ route('cart.coupon') }}">
                        @csrf
                        <label class="label" for="coupon">Coupon code</label>
                        <div class="input-group mt-1"><input class="input" id="coupon" name="code" value="{{ old('code') }}" autocomplete="off" @error('coupon') aria-invalid="true" @enderror><button class="btn btn--sm !min-h-[48px]">Apply</button></div>
                        @error('coupon')<p class="error-text mt-1" role="alert">{{ $message }}</p>@enderror
                        @if($summary->couponError)<p class="error-text mt-1" role="alert">{{ $summary->couponError }}</p>@endif
                    </form>
                @endif

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $cur }} {{ number_format($summary->subtotal) }}</dd></div>
                    @if($summary->discount)<div class="flex justify-between text-success"><dt>Discount</dt><dd>− {{ $cur }} {{ number_format($summary->discount) }}</dd></div>@endif
                    <div class="flex justify-between"><dt>Shipping @if($summary->shippingMethod)<span class="text-grey-text">({{ $summary->shippingMethod->name }}@if($summary->shippingMethod->is_sample && config('abiha.show_sample_labels')), sample rate @endif)</span>@endif</dt>
                        <dd>{{ $summary->shipping ? $cur.' '.number_format($summary->shipping) : 'Free' }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-3 text-base font-bold font-display"><dt>Total</dt><dd>{{ $cur }} {{ number_format($summary->total) }}</dd></div>
                </dl>
                <p class="text-xs text-grey-text">Shipping is an estimate. You can choose your delivery method at checkout.</p>
                <a href="{{ route('checkout') }}" class="btn btn--lg btn--block">Checkout</a>
                <a href="{{ route('shop') }}" class="block text-center text-sm link">Continue shopping</a>
            </aside>
        </div>
    @endif
</div>
@endsection
