@extends('layouts.app')
@section('title', 'Checkout')
@section('noindex', true)

@section('content')
@php $cur = config('abiha.currency.symbol'); @endphp
<div class="container py-10 lg:py-14" x-data="checkout(@js([
    'subtotal' => $summary->subtotal, 'discount' => $summary->discount,
    'shipping' => old('shipping_method', $summary->shippingMethod?->code),
    'payment' => old('payment_method', $gateways->keys()->first()),
    'province' => $prefill['province'], 'city' => $prefill['city'],
    'methods' => $shippingMethods->map(fn ($m) => ['code' => $m->code, 'cost' => $m->cost, 'free_over' => $m->free_over, 'eta' => $m->eta,
        'rules' => $m->rules->map(fn ($r) => ['province' => $r->province, 'city' => $r->city, 'cost' => $r->cost, 'free_over' => $r->free_over, 'eta' => $r->eta, 'is_available' => $r->is_available])->values()])->values(),
]))">
    <h1 class="!text-3xl lg:!text-4xl">Checkout</h1>

    <x-form-errors class="mb-6" />

    <form method="POST" action="{{ route('checkout.store') }}" class="grid gap-10 lg:grid-cols-[1fr_400px] mt-6" novalidate data-validate>
        @csrf
        <div class="space-y-10">
            @guest<p class="text-sm muted">Have an account? <a class="link" href="{{ route('login') }}">Sign in</a> for faster checkout. Guest checkout is fine too.</p>@endguest

            <section aria-labelledby="c-contact" class="space-y-4">
                <h2 id="c-contact" class="!text-xl">Contact</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="name" label="Full name" :value="$prefill['name']" required autocomplete="name" class="sm:col-span-2" />
                    <x-field name="phone" label="Phone number" type="tel" :value="$prefill['phone']" required autocomplete="tel" inputmode="tel" placeholder="03XX XXXXXXX" />
                    <x-field name="email" label="Email" type="email" :value="$prefill['email']" required autocomplete="email" help="Your order confirmation is shown on screen; keep the order number." />
                </div>
            </section>

            <section aria-labelledby="c-ship" class="space-y-4">
                <h2 id="c-ship" class="!text-xl">Delivery address</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="field">
                        <label class="label" for="f-province">Province <span class="req" aria-hidden="true">*</span></label>
                        <select class="select" id="f-province" name="province" x-model="province" required autocomplete="address-level1" @error('province') aria-invalid="true" @enderror>
                            <option value="">Select province</option>
                            @foreach(config('abiha.provinces') as $pv)<option value="{{ $pv }}" @selected($prefill['province'] === $pv)>{{ $pv }}</option>@endforeach
                        </select>
                        @error('province')<span class="error-text">{{ $message }}</span>@enderror
                    </div>
                    <x-field name="city" label="City" :value="$prefill['city']" required autocomplete="address-level2" x-model="city" />
                    <div class="field sm:col-span-2">
                        <label class="label" for="f-address">Complete address <span class="req" aria-hidden="true">*</span></label>
                        <textarea class="textarea !min-h-[96px]" id="f-address" name="address" required autocomplete="street-address" @error('address') aria-invalid="true" @enderror>{{ $prefill['address'] }}</textarea>
                        @error('address')<span class="error-text">{{ $message }}</span>@enderror
                    </div>
                    <x-field name="postal_code" label="Postal code (optional)" :value="$prefill['postal_code']" autocomplete="postal-code" inputmode="numeric" />
                    <div class="field sm:col-span-2">
                        <label class="label" for="f-notes">Order notes (optional)</label>
                        <textarea class="textarea !min-h-[80px]" id="f-notes" name="notes" maxlength="500">{{ old('notes') }}</textarea>
                    </div>
                </div>
                @auth<label class="check"><input type="checkbox" name="save_address" value="1" @checked(old('save_address', true))> Save this address to my account</label>@endauth
            </section>

            <section aria-labelledby="c-method" class="space-y-4">
                <h2 id="c-method" class="!text-xl">Shipping method</h2>
                <div class="space-y-3" role="radiogroup">
                    @forelse($shippingMethods as $m)
                        <label class="card !p-4 flex items-start gap-3 cursor-pointer has-[:checked]:border-ink has-[:checked]:bg-ivory">
                            <input type="radio" class="mt-1 accent-ink" name="shipping_method" value="{{ $m->code }}" x-model="shipping" required>
                            @if(in_array($m->code, ['dhl', 'fedex', 'ups']))<span class="carrier-tile"><x-carrier-logo :code="$m->code" :height="26" /></span>@endif
                            <span class="flex-1"><span class="font-semibold block">{{ $m->name }}</span><span class="text-sm text-grey-text"><span x-text="etaText('{{ $m->code }}')">{{ $m->eta }}</span> {{ $m->description ? '· '.$m->description : '' }}</span>
                                @if($m->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag ml-1">SAMPLE RATE</span>@endif</span>
                            <span class="font-semibold" x-text="quoteText('{{ $m->code }}')"></span>
                        </label>
                    @empty
                        <p class="alert alert--warning">Delivery options are not available right now. Please <a class="link" href="{{ route('contact') }}">contact us</a> and we will help you place your order.</p>
                    @endforelse
                </div>
                @error('shipping_method')<p class="error-text">{{ $message }}</p>@enderror
            </section>

            <section aria-labelledby="c-pay" class="space-y-4">
                <h2 id="c-pay" class="!text-xl">Payment</h2>
                <div class="space-y-3" role="radiogroup">
                    @forelse($gateways as $g)
                        <label class="card !p-4 flex items-start gap-3 cursor-pointer has-[:checked]:border-ink has-[:checked]:bg-ivory">
                            <input type="radio" class="mt-1 accent-ink" name="payment_method" value="{{ $g->code() }}" x-model="payment" required>
                            <span><span class="font-semibold block">{{ $g->label() }}</span><span class="text-sm text-grey-text">{{ $g->description() }}</span></span>
                        </label>
                    @empty
                        <p class="alert alert--warning">No payment methods are enabled yet.</p>
                    @endforelse
                </div>
                @error('payment_method')<p class="error-text">{{ $message }}</p>@enderror
                <p class="text-sm text-grey-text" x-show="payment === 'bank_transfer'" x-cloak>Our bank details are shown on the next page after you place the order.</p>
            </section>
        </div>

        <aside class="card !bg-ivory !border-transparent h-fit lg:sticky lg:top-28 space-y-5" aria-label="Order summary">
            <h2 class="!text-xl !mb-0">Order summary</h2>
            <ul class="divide-y divide-line">
                @foreach($summary->lines as $line)
                    <li class="py-3 flex gap-3 text-sm">
                        <div class="relative size-16 shrink-0 overflow-hidden rounded bg-white border border-line">
                            @if($line->product->imagePath(0))<x-picture :path="$line->product->imagePath(0)" alt="" sizes="64px" class="size-full object-contain" />@else<x-placeholder :label="false" />@endif
                            <span class="absolute -top-0 -right-0 bg-ink text-white text-[10px] size-5 grid place-content-center rounded-bl">{{ $line->quantity }}</span>
                        </div>
                        <div class="flex-1 min-w-0"><p class="font-semibold truncate">{{ $line->product->name }}</p>@if($line->options())<p class="text-grey-text text-xs">{{ $line->options() }}</p>@endif</div>
                        <p class="font-semibold">{{ $cur }} {{ number_format($line->total()) }}</p>
                    </li>
                @endforeach
            </ul>

            <div class="text-sm">
                <input type="hidden" name="from" value="checkout">
                @if($summary->coupon)
                    <p class="flex items-center justify-between rounded border border-line bg-white px-3 py-2">
                        <span class="inline-flex items-center gap-2"><x-icon name="tag" :size="16" /> <strong>{{ $summary->coupon->code }}</strong> applied</span>
                        <button type="submit" class="underline text-grey-text" name="_method" value="DELETE" formaction="{{ route('cart.coupon.remove') }}" formnovalidate>Remove</button>
                    </p>
                @else
                    <label class="label" for="coupon">Coupon code</label>
                    <div class="input-group mt-1">
                        <input class="input" id="coupon" name="code" autocomplete="off" maxlength="40" @keydown.enter.prevent="$refs.applyCoupon.click()" @error('coupon') aria-invalid="true" @enderror>
                        <button type="submit" class="btn btn--sm !min-h-[48px]" x-ref="applyCoupon" formaction="{{ route('cart.coupon') }}" formnovalidate>Apply</button>
                    </div>
                    @error('coupon')<p class="error-text mt-1" role="alert">{{ $message }}</p>@enderror
                @endif
                @if($summary->couponError)<p class="error-text mt-1" role="alert">{{ $summary->couponError }}</p>@endif
            </div>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ $cur }} {{ number_format($summary->subtotal) }}</dd></div>
                @if($summary->discount)<div class="flex justify-between text-success"><dt>Discount</dt><dd>− {{ $cur }} {{ number_format($summary->discount) }}</dd></div>@endif
                <div class="flex justify-between"><dt>Shipping</dt><dd x-text="shippingCost ? money(shippingCost) : 'Free'"></dd></div>
                <div class="flex justify-between border-t border-line pt-3 text-lg font-bold font-display"><dt>Total payable</dt><dd x-text="money(total)">{{ $cur }} {{ number_format($summary->total) }}</dd></div>
            </dl>
            <p class="alert alert--danger !text-xs" x-show="unavailable" x-cloak role="alert">This delivery method is not available to your city. Please choose another method.</p>
            <button class="btn btn--lg btn--block" :disabled="unavailable" @disabled($gateways->isEmpty() || $shippingMethods->isEmpty())>Place order</button>
            <p class="text-xs text-grey-text text-center">By placing your order you agree to our <a class="link" href="{{ route('page.shipping') }}">shipping & returns policy</a>.</p>
            <a href="{{ route('cart') }}" class="block text-center text-sm link">Edit bag / change coupon</a>
        </aside>
    </form>
</div>
@endsection
