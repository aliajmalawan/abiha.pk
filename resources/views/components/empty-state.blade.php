@props(['icon' => 'info', 'title', 'text' => null, 'action' => null, 'href' => null, 'secondary' => null, 'secondaryHref' => null, 'compact' => false])
{{-- One calm empty state for the whole site: ivory icon disc, short heading, one clear next step. --}}
<div {{ $attributes->class(['empty', 'empty--compact' => $compact]) }}>
    <span class="empty__icon" aria-hidden="true"><x-icon :name="$icon" :size="30" /></span>
    <h2>{{ $title }}</h2>
    @if($text)<p>{{ $text }}</p>@endif
    @if($action && $href || $slot->isNotEmpty())
        <div class="mt-2 flex flex-wrap justify-center gap-3">
            @if($action && $href)<a href="{{ $href }}" class="btn {{ $compact ? 'btn--sm' : '' }}">{{ $action }}</a>@endif
            @if($secondary && $secondaryHref)<a href="{{ $secondaryHref }}" class="btn btn--outline {{ $compact ? 'btn--sm' : '' }}">{{ $secondary }}</a>@endif
            {{ $slot }}
        </div>
    @endif
</div>
