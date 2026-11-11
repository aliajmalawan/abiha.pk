@extends('layouts.app')
@section('title', 'Shipping & returns')

@section('content')
<div class="container py-12 lg:py-16 max-w-3xl">
    <h1 class="!text-3xl lg:!text-4xl">Shipping &amp; returns</h1>

    <h2 class="!text-xl mt-8">Delivery</h2>
    <div class="prose-abiha">{!! $deliveryInfo ? nl2br(e($deliveryInfo)) : '<p class="muted">Delivery information will be published soon.</p>' !!}</div>

    @if($methods->isNotEmpty())
        <div class="overflow-x-auto mt-4"><table class="w-full text-sm">
            <thead><tr class="text-left border-b border-line"><th class="py-2">Method</th><th>Estimated time</th><th>Cost</th></tr></thead>
            <tbody>@foreach($methods as $m)<tr class="border-b border-line"><td class="py-3"><span class="inline-flex items-center gap-3">@if(in_array($m->code, ['dhl', 'fedex', 'ups']))<span class="carrier-tile"><x-carrier-logo :code="$m->code" :height="22" /></span>@endif{{ $m->name }}</span> @if($m->is_sample && config('abiha.show_sample_labels'))<span class="sample-flag">SAMPLE</span>@endif</td><td>{{ $m->eta ?: '-' }}</td>
                <td>{{ config('abiha.currency.symbol') }} {{ number_format($m->cost) }}@if($m->free_over) <span class="text-grey-text">(free over {{ number_format($m->free_over) }})</span>@endif</td></tr>@endforeach</tbody>
        </table></div>
    @endif

    <div class="carrier-strip" aria-label="Delivery partners">
        <span class="carrier-strip__label">Delivery partners</span>
        @foreach(['dhl', 'fedex', 'ups'] as $c)<span class="carrier-tile carrier-tile--lg"><x-carrier-logo :code="$c" :height="34" /></span>@endforeach
    </div>

    <h2 class="!text-xl mt-10">Returns</h2>
    <div class="prose-abiha">{!! $returnPolicy ? nl2br(e($returnPolicy)) : '<p class="muted">Our return policy will be published soon.</p>' !!}</div>
</div>
@endsection
