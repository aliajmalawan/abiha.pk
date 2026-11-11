<section class="section--dark on-dark relative overflow-hidden">
    @if($banner->image)<x-picture :path="$banner->image" alt="" sizes="100vw" class="absolute inset-0 size-full object-cover opacity-40" />@endif
    <div class="container relative py-20 lg:py-28 text-center max-w-3xl">
        @if($banner->eyebrow)<p class="eyebrow">{{ $banner->eyebrow }}</p>@endif
        <h2 class="!text-white">{{ $banner->title }}</h2>
        @if($banner->subtitle)<p class="mx-auto max-w-xl text-white/80">{{ $banner->subtitle }}</p>@endif
        @if($banner->cta_label)<a href="{{ url($banner->cta_url ?: '/shop') }}" class="home-btn home-btn--light mt-4">{{ $banner->cta_label }}</a>@endif
        @if($banner->is_sample && config('abiha.show_sample_labels'))<div class="mt-4"><span class="sample-flag">SAMPLE BANNER</span></div>@endif
    </div>
</section>
