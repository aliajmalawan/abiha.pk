@props(['product', 'wishlistRemove' => false, 'eager' => false, 'swatches' => false])
@php
    $hasImg = (bool) $product->imagePath(0);
    $cardSizes = '(min-width:1024px) 25vw, (min-width:640px) 33vw, 50vw';
@endphp
<article {{ $attributes->class('product-card group') }} x-data="quickAdd({{ $product->id }}, '{{ route('cart.add') }}', '{{ route('cart') }}')">
    <div class="product-card__media">
        @if($hasImg)
            <x-picture :path="$product->imagePath(0)" :alt="$product->images->first()->alt ?: $product->name" :sizes="$cardSizes" :eager="$eager" class="absolute inset-0 transition-opacity duration-500 {{ $product->imagePath(1) ? 'group-hover:opacity-0' : '' }}" />
            @if($product->imagePath(1))<x-picture :path="$product->imagePath(1)" alt="" :sizes="$cardSizes" class="absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100" />@endif
        @else
            <x-placeholder />
        @endif

        @if($label = $product->badge_label)
            <span class="badge {{ $label === 'Sold out' ? '' : 'badge--gold' }} product-card__badge" data-badge="{{ $label }}">{{ $label }}@if($label === 'Sale' && $product->discount_percent) −{{ $product->discount_percent }}%@endif</span>
        @endif

        <div x-data="wishButton({{ $product->id }}, '{{ route('wishlist.toggle', $product) }}')" class="contents">
            <button type="button" class="product-card__wish" @click.prevent="toggle()" :aria-pressed="active" aria-label="Toggle wishlist for {{ $product->name }}">
                <x-icon name="heart" ::class="active ? 'fill-gold text-gold' : ''" />
            </button>
        </div>

        {{-- Quick actions (always visible on touch, slide up on hover for desktop) --}}
        @if($product->in_stock)
            <div class="absolute inset-x-2 bottom-2 z-[1] flex gap-2 transition duration-300 lg:translate-y-2 lg:opacity-0 lg:group-hover:translate-y-0 lg:group-hover:opacity-100 lg:group-focus-within:translate-y-0 lg:group-focus-within:opacity-100">
                @if($product->has_variants)
                    <button type="button" class="btn btn--sm btn--block" @click.prevent="$store.quick.show('{{ route('product.quick', $product) }}')">Choose options</button>
                @else
                    <button type="button" class="btn btn--sm flex-1" :class="{ 'is-loading': busy, 'is-done': done }" @click.prevent="add()"><span class="btn-label">Quick add</span><span class="btn-done"><x-icon name="check" :size="16" /> Added</span></button>
                    <button type="button" class="btn btn--sm btn--light !px-3" @click.prevent="$store.quick.show('{{ route('product.quick', $product) }}')" aria-label="Quick view {{ $product->name }}"><x-icon name="eye" :size="18" /></button>
                @endif
            </div>
        @endif
    </div>
    <div>
        @if($product->category)<span class="product-card__cat">{{ $product->category->name }}</span>@endif
        <h3 class="product-card__title"><a href="{{ route('product', $product) }}">{{ $product->name }}</a></h3>
        <x-price :product="$product" />
        @if($swatches && count($product->colors))
            <ul class="flex items-center gap-1.5 mt-2" aria-label="Colours">
                @foreach(array_slice($product->colors, 0, 5) as $c)<li class="size-3.5 rounded-full border border-black/15" style="background:{{ $c['hex'] ?: '#ccc' }}" title="{{ $c['name'] }}"></li>@endforeach
                @if(count($product->colors) > 5)<li class="text-xs text-grey-text">+{{ count($product->colors) - 5 }}</li>@endif
            </ul>
        @endif
        @if($product->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag mt-1">SAMPLE</span>@endif
    </div>
    @if($wishlistRemove)
        <div class="mt-3 flex gap-2 relative z-[2]">
            @if($product->in_stock)
                <form method="POST" action="{{ route('wishlist.move', $product) }}" class="flex-1">@csrf<button class="btn btn--sm btn--block">Move to bag</button></form>
            @endif
        </div>
    @endif
</article>
