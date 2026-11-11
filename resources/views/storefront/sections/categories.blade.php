{{-- Category tiles: a tall picture with the name and a "Shop now" link under it. Two across on phones, four on desktop. --}}
<section class="section {{ $toneClass ?? '' }}">
    <div class="container">
        <div class="home-head">
            @if($section->subtitle)<p class="eyebrow !m-0">{{ $section->subtitle }}</p>@endif
            <h2>{{ $section->title }}</h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6">
            @foreach($categories as $c)
                <a href="{{ route('category', $c) }}" class="home-tile group">
                    <div class="home-tile__img">@if($c->image)<x-picture :path="$c->image" alt="" sizes="(min-width:1024px) 25vw, 50vw" />@else<x-placeholder />@endif</div>
                    <div class="mt-3 text-center">
                        <h3 class="!text-xl !mb-1">{{ $c->name }}</h3>
                        <span class="text-sm font-semibold underline underline-offset-4 decoration-gold">Shop now</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
