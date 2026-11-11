{{-- Loaded into the cart drawer. Totals come from CartService, the same source as the cart page and checkout. --}}
@php $cur = config('abiha.currency.symbol'); @endphp
@if($summary->isEmpty())
    <x-empty-state compact icon="shopping-bag" title="Your bag is empty" text="Add something you love and it will wait for you here." :href="route('shop')" action="Start shopping" class="m-auto" data-autofocus-target />
@else
    <ul class="min-h-0 flex-1 divide-y divide-line overflow-y-auto px-5">
        @foreach($summary->lines as $line)
            @php $p = $line->product; @endphp
            <li class="flex gap-4 py-4" x-data="drawerLine('{{ route('cart.update', $line->key) }}', '{{ route('cart.remove', $line->key) }}')" :class="busy && 'opacity-60'">
                <a href="{{ route('product', $p) }}" class="relative block aspect-[4/5] w-20 shrink-0 overflow-hidden rounded bg-ivory border border-line">
                    @if($p->imagePath(0))<x-picture :path="$p->imagePath(0)" alt="" sizes="80px" class="size-full object-contain" />@else<x-placeholder :label="false" />@endif
                </a>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('product', $p) }}" class="font-display font-semibold text-sm no-underline hover:underline">{{ $p->name }}</a>
                        <button type="button" class="-mr-1 grid size-8 shrink-0 place-content-center rounded text-grey-text hover:text-ink" @click="remove()" aria-label="Remove {{ $p->name }} from your bag"><x-icon name="trash-2" :size="16" /></button>
                    </div>
                    @if($line->options())<p class="text-xs text-grey-text">{{ $line->options() }}</p>@endif
                    @if($line->notice)<p class="mt-1 text-xs text-warning">{{ $line->notice }}</p>@endif
                    <div class="mt-3 flex items-center justify-between">
                        @if($line->stock > 0)
                            <div class="inline-flex items-center rounded-md border border-input" role="group" aria-label="Quantity for {{ $p->name }}">
                                <button type="button" class="grid size-9 place-content-center" @click="set({{ $line->quantity - 1 }})" :disabled="busy" aria-label="Decrease quantity"><x-icon name="minus" :size="14" /></button>
                                <output class="w-7 text-center text-sm font-semibold">{{ $line->quantity }}</output>
                                <button type="button" class="grid size-9 place-content-center" @click="set({{ $line->quantity + 1 }})" :disabled="busy || {{ $line->quantity >= min($line->stock, 20) ? 'true' : 'false' }}" aria-label="Increase quantity"><x-icon name="plus" :size="14" /></button>
                            </div>
                        @else<span></span>@endif
                        <p class="price !mb-0 text-sm">{{ $cur }} {{ number_format($line->stock > 0 ? $line->total() : 0) }}</p>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>
    <footer class="space-y-3 border-t border-line bg-white px-5 py-4">
        <div class="flex items-center justify-between font-display font-bold"><span>Subtotal</span><span>{{ $cur }} {{ number_format($summary->subtotal) }}</span></div>
        <p class="text-xs text-grey-text">Shipping and coupons are applied at checkout.</p>
        <a href="{{ route('checkout') }}" class="btn btn--lg btn--block" data-autofocus>Checkout</a>
        <a href="{{ route('cart') }}" class="block text-center text-sm link">View full bag</a>
    </footer>
@endif
