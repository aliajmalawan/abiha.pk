{{-- Hero: one big banner (several cross-fade).
     With a picture the picture is always shown whole and unveiled. The banner's own words (eyebrow, headline, text, button) are then either
     drawn in a white card over the picture (below it on phones, tablets and small laptops), or left out when the picture already has its own
     design ("show text" unticked in Admin → Banners), in which case only the button sits over it. Without a picture the banner is a solid
     black block with the words. The words arrive one after another each time a slide appears (see home.css; no animation for visitors who
     asked their device for less motion). The first slide is marked active in the HTML, so its words animate on load even before JavaScript runs. --}}
<section class="relative" x-data="{ i: 0, n: {{ $banners->count() }}, t: null,
        go(k) { this.i = (k + this.n) % this.n },
        play() { if (this.n > 1) this.t = setInterval(() => this.go(this.i + 1), 8000) },
        init() { this.play() } }"
         @mouseenter="clearInterval(t)" @mouseleave="play()" @focusin="clearInterval(t)" @keydown.left="go(i - 1)" @keydown.right="go(i + 1)"
         aria-roledescription="carousel" aria-label="Featured">
    <div class="grid">
        @foreach($banners as $k => $b)
            @php $tag = $k === 0 ? 'h1' : 'p'; $link = $b->cta_url ?: ($b->cta_label ? route('shop') : null); @endphp
            <div class="hero-slide {{ $k === 0 ? 'is-active' : '' }} col-start-1 row-start-1 transition-opacity duration-700"
                 :class="{ 'is-active': i === {{ $k }}, 'opacity-100': i === {{ $k }}, 'opacity-0 pointer-events-none': i !== {{ $k }} }" :aria-hidden="i !== {{ $k }}"
                 role="group" aria-roledescription="slide" aria-label="{{ $k + 1 }} of {{ $banners->count() }}">
                @if($b->image)
                    <div class="relative bg-ivory [&_picture]:block">
                        @unless($b->show_text)<{{ $tag }} class="sr-only">{{ $b->title }}</{{ $tag }}>@endunless
                        @if(! $b->show_text && $link)<a href="{{ url($link) }}" class="block" aria-label="{{ $b->cta_label ?: $b->title }}">@endif
                            <x-picture :path="$b->image" :alt="$b->show_text ? '' : $b->title" sizes="100vw" :priority="$k === 0" class="w-full h-auto max-sm:aspect-[4/3] max-sm:object-cover max-sm:object-[72%_50%]" />
                        @if(! $b->show_text && $link)</a>@endif

                        @if($b->show_text)
                            {{-- Over the picture, as a compact card on the left, only on wide screens (1280px and up) where it fits in the picture's empty space;
                                 below the picture, overlapping it a little, on phones, tablets and small laptops. --}}
                            <div class="xl:absolute xl:inset-y-0 xl:left-0 xl:flex xl:items-center xl:pl-8 2xl:pl-[4%] xl:pointer-events-none">
                                <div class="hero-card xl:w-[clamp(19.5rem,22vw,34rem)] max-xl:relative max-xl:z-10 max-xl:-mt-10 max-xl:mx-4 sm:max-xl:mx-8 max-xl:mb-6 xl:pointer-events-auto">
                                    @if($b->eyebrow)<p class="hero-eyebrow hx" style="--d:180ms">{{ $b->eyebrow }}</p>@endif
                                    <{{ $tag }} class="hero-title hx" style="--d:320ms">{{ $b->title }}</{{ $tag }}>
                                    <hr class="hero-rule hx-rule" style="--d:520ms">
                                    @if($b->subtitle)<p class="hero-sub hx" style="--d:640ms">{{ $b->subtitle }}</p>@endif
                                    @if($link && $b->cta_label)<div class="hx" style="--d:800ms"><a href="{{ url($link) }}" class="home-btn">{{ $b->cta_label }} <x-icon name="arrow-right" :size="18" /></a></div>@endif
                                    @if($b->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag mt-4">SAMPLE BANNER</span>@endif
                                </div>
                            </div>
                        @else
                            @if($link && $b->cta_label)<span class="home-btn home-btn--light hx pointer-events-none absolute left-3 bottom-3 sm:left-10 sm:bottom-10 max-sm:!min-h-[40px] max-sm:!px-5 max-sm:!text-xs" style="--d:500ms">{{ $b->cta_label }}</span>@endif
                            @if($b->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag absolute right-3 top-3">SAMPLE BANNER</span>@endif
                        @endif
                    </div>
                @else
                    <div class="hero-plain relative isolate flex items-end sm:items-center bg-ink text-white min-h-[440px] sm:min-h-[520px] lg:min-h-[640px]">
                        <div class="container py-14 sm:py-20">
                            <div class="max-w-xl">
                                @if($b->eyebrow)<p class="hero-eyebrow hx" style="--d:120ms">{{ $b->eyebrow }}</p>@endif
                                <{{ $tag }} class="hero-title hx" style="--d:260ms">{{ $b->title }}</{{ $tag }}>
                                <hr class="hero-rule hx-rule" style="--d:460ms">
                                @if($b->subtitle)<p class="hero-sub hx" style="--d:580ms">{{ $b->subtitle }}</p>@endif
                                @if($b->cta_label)<div class="hx" style="--d:740ms"><a href="{{ $b->cta_url ?: route('shop') }}" class="home-btn home-btn--light">{{ $b->cta_label }} <x-icon name="arrow-right" :size="18" /></a></div>@endif
                                @if($b->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag mt-4">SAMPLE BANNER</span>@endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @if($banners->count() > 1)
        <button type="button" class="home-arrow absolute left-3 top-1/2 -translate-y-1/2 hidden sm:grid" @click="go(i - 1)" aria-label="Previous slide"><x-icon name="chevron-left" :size="22" /></button>
        <button type="button" class="home-arrow absolute right-3 top-1/2 -translate-y-1/2 hidden sm:grid" @click="go(i + 1)" aria-label="Next slide"><x-icon name="chevron-right" :size="22" /></button>
        <div class="absolute inset-x-0 bottom-4 flex justify-center gap-2" role="tablist" aria-label="Choose slide">
            @foreach($banners as $k => $b)
                <button type="button" role="tab" class="size-2.5 rounded-full border border-white ring-1 ring-black/20" :class="i === {{ $k }} ? 'bg-white' : 'bg-transparent'" :aria-selected="i === {{ $k }}" @click="go({{ $k }})" aria-label="Slide {{ $k + 1 }}"></button>
            @endforeach
        </div>
    @endif
</section>
