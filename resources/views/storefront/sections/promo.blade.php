{{-- Promotional banner. The words come from the banner the admin placed as "Promotional banner". The deal line is the real product with
     the biggest discount right now; with nothing discounted it is simply not shown. --}}
@php $img = $banner->image ?: $deal?->imagePath(0); $pct = $deal?->discount_percent; @endphp
<section class="section {{ $toneClass ?? '' }}">
    <div class="container grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
        <div class="relative overflow-hidden bg-ivory border border-line {{ $img ? '[&_picture]:block' : 'aspect-[4/3]' }}">
            @if($pct)<span class="absolute left-4 top-4 z-10 bg-ink px-3 py-1.5 text-sm font-bold text-champagne">−{{ $pct }}%</span>@endif
            @if($img)<x-picture :path="$img" :alt="$banner->title" sizes="(min-width:1024px) 50vw, 100vw" class="w-full h-auto" />@else<x-placeholder />@endif
        </div>
        <div class="max-lg:text-center">
            @if($banner->eyebrow)<p class="eyebrow">{{ $banner->eyebrow }}</p>@endif
            <h2>{{ $banner->title }}</h2>
            <hr class="gold-rule max-lg:mx-auto">
            @if($banner->subtitle)<p class="lead max-lg:mx-auto">{{ $banner->subtitle }}</p>@endif
            @if($deal)
                <p class="text-sm">Featured: <a href="{{ route('product', $deal) }}" class="font-semibold underline underline-offset-4 decoration-gold">{{ $deal->name }}</a>
                    <strong class="ml-2">{{ config('abiha.currency.symbol') }} {{ number_format($deal->price) }}</strong> <s class="text-grey-text">{{ config('abiha.currency.symbol') }} {{ number_format($deal->compare_price) }}</s></p>
            @endif
            @if($banner->cta_label)<a href="{{ url($banner->cta_url ?: '/shop') }}" class="home-btn mt-2">{{ $banner->cta_label }}</a>@endif
            @if($banner->is_sample && config('abiha.show_sample_labels'))<div class="mt-4"><span class="sample-flag">SAMPLE BANNER</span></div>@endif
        </div>
    </div>
</section>
