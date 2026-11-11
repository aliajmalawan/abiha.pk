@props(['product'])
<p {{ $attributes->class(['price !mb-0']) }}>
    @if($product->price_varies)<span class="muted font-normal text-sm">From</span> @endif
    {{ config('abiha.currency.symbol') }} {{ number_format($product->display_price) }}
    @if($product->is_on_sale)<s class="text-sm">{{ config('abiha.currency.symbol') }} {{ number_format($product->display_compare_price) }}</s>@endif
</p>
