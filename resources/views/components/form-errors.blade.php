@props(['bag' => 'default'])
{{-- Summary at the top of a form after a failed submit. Each item jumps to its field. Focused on load so screen readers announce it. --}}
@php
    $bagErrors = $errors->getBag($bag);
    // keys that belong to page-level alerts, not to a field on this form
    $keys = collect($bagErrors->keys())->reject(fn ($k) => in_array($k, ['cart', 'wishlist', 'order', 'coupon', 'delete', 'status', 'payment', 'inventory'], true))->values();
@endphp
@if($keys->isNotEmpty())
    <div class="error-summary" role="alert" tabindex="-1" data-error-summary>
        <strong>{{ $keys->count() === 1 ? 'There is 1 problem to fix:' : 'There are '.$keys->count().' problems to fix:' }}</strong>
        <ul>
            @foreach($keys as $k)
                <li><a href="#f-{{ str_replace('.', '-', $k) }}">{{ $bagErrors->first($k) }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
