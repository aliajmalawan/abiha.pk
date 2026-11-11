@php
    $url = \App\Models\Setting::get('social.instagram');
    $pics = array_slice(array_values((array) $section->setting('images', [])), 0, 6);
@endphp
@if($url || $pics || config('abiha.show_sample_labels'))
<section class="section {{ $toneClass ?? '' }}">
    <div class="container">
        <div class="text-center">
            <p class="eyebrow">Social</p>
            <h2>{{ $section->title }}</h2>
            @if($url)<a href="{{ $url }}" target="_blank" rel="noopener" class="link">Follow us on Instagram</a>
            @elseif(config('abiha.show_sample_labels'))<p class="muted">Instagram link to be added <span class="sample-flag">SAMPLE</span></p>@endif
        </div>
        {{-- Pictures are uploaded by staff under Admin → Homepage → Social block (never scraped, never invented). They link to the profile. --}}
        @if($pics || config('abiha.show_sample_labels'))
            <div class="grid grid-cols-3 gap-2 mt-10 lg:grid-cols-6">
                @forelse($pics as $i => $path)
                    <a href="{{ $url ?: '#' }}" @if($url) target="_blank" rel="noopener" @endif class="relative block aspect-square overflow-hidden rounded-md bg-white border border-line" aria-label="Social post {{ $i + 1 }}">
                        <x-picture :path="$path" :alt="'Social post '.($i + 1)" sizes="(min-width:1024px) 16vw, 33vw" class="size-full object-contain" />
                    </a>
                @empty
                    @for($i = 0; $i < 6; $i++)
                        <a href="{{ $url ?: '#' }}" @if($url) target="_blank" rel="noopener" @endif class="relative block aspect-square overflow-hidden rounded-md bg-white border border-line" aria-label="Instagram post {{ $i + 1 }}"><x-placeholder :label="false" /></a>
                    @endfor
                @endforelse
            </div>
        @endif
    </div>
</section>
@endif
