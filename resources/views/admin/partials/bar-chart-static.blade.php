{{-- No-JavaScript fallback for the Chart.js chart: the same bars as plain server-rendered SVG. Props as bar-chart.blade.php. --}}
@php
    $max = max(1, collect($items)->max('value'));
    $n = max(1, count($items));
    $w = 720; $h = 230; $padTop = 14; $padBottom = 34; $gap = $n > 16 ? 3 : 10;
    $bw = ($w - $gap * ($n - 1)) / $n;
    $plot = $h - $padTop - $padBottom;
    $every = $n > 16 ? 6 : 1;
@endphp
<svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full h-auto" role="img" aria-label="{{ $caption }}" preserveAspectRatio="xMidYMid meet">
    <line x1="0" y1="{{ $h - $padBottom }}" x2="{{ $w }}" y2="{{ $h - $padBottom }}" stroke="var(--abiha-border)" stroke-width="1" />
    @foreach($items as $i => $it)
        @php $bh = $it['value'] > 0 ? max(2, ($it['value'] / $max) * $plot) : 0; $x = $i * ($bw + $gap); $last = $i === $n - 1; @endphp
        <rect x="{{ round($x, 2) }}" y="{{ round($h - $padBottom - $bh, 2) }}" width="{{ round($bw, 2) }}" height="{{ round($bh, 2) }}" rx="2" fill="{{ $last ? 'var(--abiha-gold)' : 'var(--abiha-black)' }}" />
        @if($i % $every === 0 || $last)
            <text x="{{ round($x + $bw / 2, 2) }}" y="{{ $h - 10 }}" text-anchor="middle" font-size="16" fill="var(--grey-text)" font-family="Inter, sans-serif">{{ $it['label'] }}</text>
        @endif
    @endforeach
</svg>
