{{--
  Accessible bar chart drawn by Chart.js (loaded only where a chart exists). A data table sits under every chart, and
  visitors without JavaScript get the same bars as plain SVG.
  Props: $items = [['label' => 'Jan', 'value' => 1200, 'title' => '…', 'title_label' => 'January 2026'], …], $caption (string), $currency (bool, default true)
--}}
@php
    $cur = config('abiha.currency.symbol');
    $showCurrency = $currency ?? true;
    $empty = collect($items)->sum('value') <= 0;
    $payload = [
        'labels' => collect($items)->pluck('label')->values()->all(),
        'titles' => collect($items)->map(fn ($it) => $it['title_label'] ?? $it['label'])->values()->all(),
        'values' => collect($items)->pluck('value')->map(fn ($v) => (int) $v)->values()->all(),
        'currency' => $showCurrency, 'symbol' => $cur,
    ];
@endphp
<figure class="m-0">
    <div class="relative h-60 w-full">
        <canvas data-chart="{{ json_encode($payload) }}" role="img" aria-label="{{ $caption }}"></canvas>
        <noscript>@include('admin.partials.bar-chart-static', ['items' => $items, 'caption' => $caption])</noscript>
    </div>
    @if($empty)<p class="text-center text-sm text-grey-text mt-3">No sales in this period yet.</p>@endif
    <figcaption class="sr-only">{{ $caption }}</figcaption>
    <details class="text-xs mt-2"><summary class="cursor-pointer text-grey-text">View as table</summary>
        <table class="table mt-2"><thead><tr><th>Period</th><th class="num">{{ $showCurrency ? 'Revenue' : 'Value' }}</th></tr></thead><tbody>
            @foreach($items as $it)<tr><td>{{ $it['title_label'] ?? $it['label'] }}</td><td class="num">{{ $showCurrency ? $cur.' ' : '' }}{{ number_format($it['value']) }}</td></tr>@endforeach
        </tbody></table></details>
</figure>
@once
    @vite('resources/js/admin-charts.js')
@endonce
