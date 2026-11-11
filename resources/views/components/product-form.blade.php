@props(['product', 'compact' => false])
{{-- Single Alpine component drives price, stock and SKU from the selected variant. The server re-validates everything on add. --}}
<div x-data="productForm({ productId: {{ $product->id }}, payload: @js($product->variantPayload()), addUrl: '{{ route('cart.add') }}', cartUrl: '{{ route('cart') }}' })" class="space-y-6">
    @php
        // Everything below is ALSO drawn by the server, so the old price, the saving and "From" are right before JavaScript runs
        // (no empty gold pill flashing, no layout jump) and are still there when JavaScript is off. Alpine then keeps them in step with the selected variant.
        $sym = config('abiha.currency.symbol');
        $shown = $product->display_price;
        $cmp = $product->display_compare_price;
        $disc = $cmp && $cmp > $shown ? (int) round((1 - $shown / $cmp) * 100) : 0;
        $hasFrom = $product->has_variants && $product->variants->map(fn ($v) => $v->effective_price)->unique()->count() > 1;
    @endphp
    <div>
        <div class="flex flex-wrap items-baseline gap-3">
            <p class="price !text-3xl !mb-0" aria-live="polite">
                <span x-show="fromLabel" @unless($hasFrom) x-cloak @endunless class="text-sm font-normal text-grey-text">From </span><span x-text="money(price)">{{ $sym }} {{ number_format($shown) }}</span>
            </p>
            <s class="text-grey-text" x-show="compare" @unless($cmp && $cmp > $shown) x-cloak @endunless x-text="compare ? money(compare) : ''">@if($cmp && $cmp > $shown){{ $sym }} {{ number_format($cmp) }}@endif</s>
            <span class="badge badge--gold" x-show="discount > 0" @unless($disc > 0) x-cloak @endunless x-text="'Save ' + discount + '%'">@if($disc > 0)Save {{ $disc }}%@endif</span>
        </div>
        <p class="mt-2 text-sm flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="inline-flex items-center gap-1.5" :class="!complete ? 'text-grey-text' : (inStock ? 'text-success' : 'text-danger')">
                <span class="size-2 rounded-full bg-current"></span><span x-text="stockLabel">{{ $product->in_stock ? 'In stock' : 'Out of stock' }}</span>
            </span>
            <span class="text-grey-text">SKU: <span x-text="sku">{{ $product->sku }}</span></span>
        </p>
    </div>

    <template x-if="colors.length">
        <fieldset>
            <legend class="label mb-2">Colour<span class="font-normal text-grey-text">: <span x-text="color ?? 'Select'"></span></span></legend>
            <div class="flex flex-wrap gap-3" role="radiogroup">
                <template x-for="c in colors" :key="c.name">
                    <button type="button" role="radio" class="swatch" :aria-checked="color === c.name" :aria-label="c.name + (optionAvailable('color', c.name) ? '' : ' (unavailable)')"
                            :style="`--c:${c.hex || '#ccc'}`" :class="!optionAvailable('color', c.name) && 'opacity-35 line-through'" @click="pick('color', c.name)"></button>
                </template>
            </div>
        </fieldset>
    </template>

    <template x-if="sizes.length">
        <fieldset>
            <legend class="label mb-2">Size<span class="font-normal text-grey-text">: <span x-text="size ?? 'Select'"></span></span></legend>
            <div class="flex flex-wrap gap-2" role="radiogroup">
                <template x-for="s in sizes" :key="s">
                    <button type="button" role="radio" class="chip" :aria-checked="size === s" :disabled="!optionAvailable('size', s)" @click="pick('size', s)" x-text="s"></button>
                </template>
            </div>
            @if(! $compact && trim((string) \App\Models\Setting::get('size.guide')) !== '')
                <button type="button" class="link hit text-xs mt-2" aria-haspopup="dialog" @click="$dispatch('open-size-guide')">Size guide</button>
            @endif
        </fieldset>
    </template>

    <p class="error-text" x-show="error" x-text="error" role="alert"></p>

    <div class="flex items-stretch gap-2 sm:gap-3">
        <div class="inline-flex items-center border border-input rounded-md" role="group" aria-label="Quantity">
            <button type="button" class="size-11 grid place-content-center" @click="dec()" aria-label="Decrease quantity" :disabled="qty <= 1"><x-icon name="minus" :size="16" /></button>
            <output class="w-8 text-center font-semibold" x-text="qty" aria-live="polite">1</output>
            <button type="button" class="size-11 grid place-content-center" @click="inc()" aria-label="Increase quantity" :disabled="qty >= Math.min(20, stock || 1)"><x-icon name="plus" :size="16" /></button>
        </div>
        <button type="button" data-main-add class="btn flex-1 min-w-0 !px-3" :class="{ 'is-loading': busy, 'is-done': justAdded }" :disabled="complete && !inStock" @click="add(false)">
            <span class="btn-label" x-text="complete && !inStock ? 'Sold out' : 'Add to bag'">Add to bag</span>
            <span class="btn-done"><x-icon name="check" :size="16" /> Added</span>
        </button>
        <div x-data="wishButton({{ $product->id }}, '{{ route('wishlist.toggle', $product) }}')">
            <button type="button" class="btn btn--outline !px-3 h-full" @click="toggle()" :aria-pressed="active" aria-label="Toggle wishlist"><x-icon name="heart" ::class="active ? 'fill-gold text-gold' : ''" /></button>
        </div>
    </div>
    <button type="button" class="btn btn--gold btn--block" :disabled="complete && !inStock" @click="add(true)">Buy now</button>

    @unless($compact)
        {{-- Phones: once the main button has scrolled out of view, the price and an add-to-bag button stay at the bottom of the screen. --}}
        <div x-data="{ show: false }" x-init="
                const main = document.querySelector('[data-main-add]');
                if (main && 'IntersectionObserver' in window) { new IntersectionObserver(([e]) => { show = !e.isIntersecting; }, { threshold: 0.1 }).observe(main); }
                $watch('show', (v) => { document.body.style.paddingBottom = v && window.matchMedia('(max-width: 1023px)').matches ? '84px' : ''; });">
            <div x-cloak x-show="show" x-transition.opacity.duration.150ms role="region" aria-label="Add to bag"
                 class="lg:hidden fixed inset-x-0 bottom-0 z-[90] bg-white border-t border-line shadow-lift px-4 pt-3" style="padding-bottom:max(.75rem, var(--safe-bottom))">
                <div class="flex items-center gap-3">
                    <div class="min-w-0"><p class="text-xs text-grey-text truncate">{{ $product->name }}</p><p class="font-bold" x-text="money(price)">{{ config('abiha.currency.symbol') }} {{ number_format($product->price) }}</p></div>
                    <button type="button" class="btn flex-1 !min-h-[48px]" :class="{ 'is-loading': busy, 'is-done': justAdded }" :disabled="complete && !inStock"
                            @click="if (!complete) { add(false); document.querySelector('[data-main-add]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }); } else { add(false); }">
                        <span class="btn-label" x-text="complete && !inStock ? 'Sold out' : (complete ? 'Add to bag' : 'Choose options')">Add to bag</span>
                        <span class="btn-done"><x-icon name="check" :size="16" /> Added</span>
                    </button>
                </div>
            </div>
        </div>
    @endunless
</div>
