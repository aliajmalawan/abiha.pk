@props(['path', 'alt' => '', 'sizes' => '100vw', 'eager' => false, 'priority' => false])
@php
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $srcset = \App\Support\Img::srcset($path);
    $dim = \App\Support\Img::dimensions($path);
@endphp
{{-- WebP variants when we have them, the original as the universal fallback. width/height reserve the space (no layout shift). --}}
<picture>
    @if($srcset)<source type="image/webp" srcset="{{ $srcset }}" sizes="{{ $sizes }}">@endif
    <img src="{{ \App\Support\Img::url($path) }}" alt="{{ $alt }}" @if($dim) width="{{ $dim[0] }}" height="{{ $dim[1] }}" @endif
         loading="{{ $eager || $priority ? 'eager' : 'lazy' }}" decoding="async" @if($priority) fetchpriority="high" @endif {{ $attributes }}>
</picture>
