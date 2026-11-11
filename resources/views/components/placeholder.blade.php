@props(['label' => true])
{{-- Neutral stand-in until real product photography is uploaded. Never fake product imagery.
     Pass :label="false" on small thumbnails where the caption would not fit. --}}
<div {{ $attributes->class(['absolute inset-0 grid place-content-center text-grey-text']) }} aria-hidden="true">
    <span class="grid place-items-center gap-2 opacity-60">
        <svg width="{{ $label ? 34 : 24 }}" height="{{ $label ? 34 : 24 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/></svg>
        @if($label)<span class="text-[11px] tracking-widest uppercase">Image coming soon</span>@endif
    </span>
</div>
