{{-- One row of products in the layout the admin chose: carousel (swipe / arrows), grid, or "shop the look" (tall pictures). Every item is a normal link. --}}
@php
    $cur = config('abiha.currency.symbol');
    $isRail = $layout !== 'grid';
    $basis = $layout === 'lookbook' ? 'basis-[56%] sm:basis-[36%] md:basis-[28%] lg:basis-[19%]' : 'basis-[62%] sm:basis-[40%] md:basis-[31%] lg:basis-[23.5%]';
@endphp
@if($layout === 'grid')
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-3 gap-y-8 sm:gap-x-5" data-reveal-group>
        @foreach($products as $product)<x-product-card :product="$product" :swatches="true" data-reveal />@endforeach
    </div>
@else
    <div class="relative" x-data="{ atStart: true, atEnd: false,
            upd() { const r = this.$refs.rail; this.atStart = r.scrollLeft < 4; this.atEnd = r.scrollLeft + r.clientWidth >= r.scrollWidth - 4 },
            step(d) { const r = this.$refs.rail; r.scrollBy({ left: d * r.clientWidth * 0.85, behavior: 'smooth' }) } }"
         x-init="upd(); $nextTick(() => upd())" @resize.window.debounce.150ms="upd()">
        <div x-ref="rail" data-reveal-group @scroll.passive="upd()" role="region" tabindex="0" aria-label="{{ $label }}"
             class="flex gap-3 sm:gap-5 overflow-x-auto snap-x snap-mandatory pb-3 -mx-4 px-4 sm:mx-0 sm:px-0 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach($products as $product)
                <div class="snap-start shrink-0 {{ $basis }}" data-reveal>
                    @if($layout === 'lookbook')
                        <a href="{{ route('product', $product) }}" class="home-look">
                            @if($product->imagePath(0))<x-picture :path="$product->imagePath(0)" :alt="$product->name" sizes="(min-width:1024px) 19vw, 56vw" />@else<x-placeholder />@endif
                            <span class="home-look__bar">
                                @if($product->imagePath(1) ?: $product->imagePath(0))<x-picture :path="$product->imagePath(1) ?: $product->imagePath(0)" alt="" sizes="46px" class="home-look__thumb" />@endif
                                <span class="min-w-0"><span class="block text-sm font-semibold truncate">{{ $product->name }}</span><span class="block text-sm text-grey-text">{{ $cur }} {{ number_format($product->price) }}</span></span>
                            </span>
                        </a>
                    @else
                        <x-product-card :product="$product" :swatches="true" />
                    @endif
                </div>
            @endforeach
        </div>
        <button type="button" class="home-arrow absolute -left-4 top-[38%] hidden lg:grid" @click="step(-1)" :disabled="atStart" aria-label="Previous"><x-icon name="chevron-left" :size="22" /></button>
        <button type="button" class="home-arrow absolute -right-4 top-[38%] hidden lg:grid" @click="step(1)" :disabled="atEnd" aria-label="Next"><x-icon name="chevron-right" :size="22" /></button>
    </div>
@endif
@if(!empty($link))
    <div class="mt-8 text-center"><a href="{{ url($link) }}" class="home-btn home-btn--outline">View all</a></div>
@endif
