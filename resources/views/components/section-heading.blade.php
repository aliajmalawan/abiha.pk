@props(['section', 'link' => null])
@php $link = $link ?? $section->setting('link'); @endphp
<div class="flex items-end justify-between gap-6 max-sm:flex-col max-sm:items-center max-sm:text-center">
    <div>
        @if($section->subtitle)<p class="eyebrow">{{ $section->subtitle }}</p>@endif
        <h2 class="!mb-0 title-bar max-sm:text-center">{{ $section->title }}</h2>
    </div>
    @if($link)<a href="{{ url($link) }}" class="btn btn--outline btn--sm shrink-0 hidden sm:inline-flex">View all <x-icon name="arrow-right" :size="16" /></a>@endif
</div>
