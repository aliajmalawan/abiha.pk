<section class="section {{ $toneClass ?? '' }}">
    <div class="container">
        <div class="home-head">
            @if($section->subtitle)<p class="eyebrow !m-0">{{ $section->subtitle }}</p>@endif
            <h2>{{ $section->title }}</h2>
            <hr class="gold-rule">
        </div>
        <div class="grid grid--3 mt-10">
            @foreach($testimonials as $t)
                <figure class="card !p-8 flex flex-col gap-4 relative">
                    <span class="absolute right-6 top-3 font-display text-7xl leading-none text-gold/30" aria-hidden="true">&ldquo;</span>
                    <x-stars :rating="$t->rating" />
                    <blockquote class="text-base leading-relaxed">“{{ $t->body }}”</blockquote>
                    <figcaption class="mt-auto text-sm">
                        <strong class="font-display">{{ $t->name }}</strong>@if($t->location)<span class="muted"> · {{ $t->location }}</span>@endif
                        @if($t->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag ml-2">SAMPLE</span>@endif
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
