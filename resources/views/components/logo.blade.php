@props(['width' => 160, 'mono' => false, 'priority' => false, 'lazy' => false])
@php
    // An owner-uploaded logo (admin → Settings) is served through the same responsive pipeline as every other upload.
    $customPath = $mono ? null : \App\Models\Setting::get('site.logo');
    $customPath = $customPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($customPath) ? $customPath : null;
    $alt = config('abiha.name').'®';
    $brand = $mono ? 'abiha-monogram' : 'abiha-logo-full';
    $widths = $mono ? [96, 192, 320] : [240, 360, 480, 640];
    // The PNG is only the fallback for browsers without WebP; pick the smaller file when the logo is shown small.
    $fallback = $mono ? 'assets/brand/abiha-monogram-512.png' : ($width <= 150 ? 'assets/brand/abiha-logo-full-300.png' : 'assets/brand/abiha-logo-full-600.png');
    $dim = $customPath ? \App\Support\Img::dimensions($customPath) : null;       // an uploaded logo keeps its own proportions
    $ratio = $dim ? $dim[0].' / '.$dim[1] : null;
    $height = $mono ? $width : round($width * 836 / 1160);
@endphp
{{-- Official ABIHA® artwork, only ever resized/re-encoded, never redrawn or retyped. Width drives height; clear space is built in. --}}
<span {{ $attributes->class(['logo', 'logo--mono' => $mono, 'logo--own' => (bool) $customPath]) }} style="--logo-w: {{ $width }}px;@if($ratio) --logo-ar: {{ $ratio }};@endif">
    @if($customPath)
        <x-picture :path="$customPath" :alt="$alt" :sizes="$width.'px'" :priority="$priority" :eager="! $lazy" />
    @else
        <picture>
            <source type="image/webp" sizes="{{ $width }}px" srcset="{{ collect($widths)->map(fn ($w) => asset("assets/brand/{$brand}-{$w}.webp").' '.$w.'w')->implode(', ') }}">
            <img src="{{ asset($fallback) }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" decoding="async"
                 loading="{{ $lazy ? 'lazy' : 'eager' }}" @if($priority) fetchpriority="high" @endif>
        </picture>
    @endif
</span>
