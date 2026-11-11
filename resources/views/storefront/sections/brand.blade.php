{{-- The brand story: one picture, the admin's text, then figures counted from the catalogue. --}}
@php
    $pics = $showcase->filter(fn ($p) => $p->imagePath(0))->values();
    $main = $section->setting('image') ?: $pics->get(0)?->imagePath(0);
    $figures = collect($stats)->filter(fn ($n) => $n > 0);
@endphp
<section class="section {{ $toneClass ?? '' }}">
    <div class="container grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div class="relative overflow-hidden bg-ivory border border-line {{ $main ? '[&_picture]:block' : 'aspect-[5/4]' }}">
            @if($main)<x-picture :path="$main" :alt="$section->title ?: ''" sizes="(min-width:1024px) 50vw, 100vw" class="w-full h-auto" />@else<x-placeholder />@endif
        </div>
        <div class="max-w-xl max-lg:text-center max-lg:mx-auto">
            <p class="eyebrow">Our story</p>
            <h2>{{ $section->title }}</h2>
            <hr class="gold-rule max-lg:mx-auto">
            <p class="lead max-lg:mx-auto">{{ $section->setting('body') }}</p>
            @if($figures->isNotEmpty())
                <div class="home-stats mt-6">
                    @foreach($figures as $label => $n)<div><b>{{ $n }}</b><span class="text-sm text-grey-text">{{ $label }}</span></div>@endforeach
                </div>
            @endif
            @if($section->setting('cta_label'))<a href="{{ $section->setting('cta_url', '#') }}" class="home-btn home-btn--outline mt-6">{{ $section->setting('cta_label') }}</a>@endif
            @if(config('abiha.show_sample_labels'))<div class="mt-4"><span class="sample-flag">SAMPLE COPY</span></div>@endif
        </div>
    </div>
</section>
