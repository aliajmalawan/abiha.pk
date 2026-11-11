{{-- Collections: a tall picture, the name, the admin's description and a live piece count. --}}
<section class="section {{ $toneClass ?? '' }}">
    <div class="container">
        <div class="home-head">
            @if($section->subtitle)<p class="eyebrow !m-0">{{ $section->subtitle }}</p>@endif
            <h2>{{ $section->title }}</h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid-cols-2 {{ $collections->count() >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }} gap-3 sm:gap-6">
            @foreach($collections as $col)
                <a href="{{ route('collection', $col) }}" class="home-col group">
                    <div class="home-col__img">@if($col->image)<x-picture :path="$col->image" alt="" sizes="(min-width:1024px) 25vw, 50vw" />@else<x-placeholder />@endif</div>
                    <div class="mt-3 text-center">
                        <h3 class="!text-xl !mb-1">{{ $col->name }}</h3>
                        @if($col->description)<p class="text-sm muted !mb-1 line-clamp-2">{{ $col->description }}</p>@endif
                        <span class="text-sm font-semibold underline underline-offset-4 decoration-gold">{{ $col->active_products_count }} {{ \Illuminate\Support\Str::plural('piece', $col->active_products_count) }} · Shop the edit</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
