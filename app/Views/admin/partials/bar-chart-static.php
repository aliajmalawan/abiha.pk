<?php
/** No-JavaScript fallback for the Chart.js chart: the same bars as plain server-rendered SVG. Props as bar-chart.php. */
$values = array_map(static fn ($it) => (int) $it['value'], $items);
$max = max(1, $values ? max($values) : 0);
$n = max(1, count($items));
$w = 720; $h = 230; $padTop = 14; $padBottom = 34; $gap = $n > 16 ? 3 : 10;
$bw = ($w - $gap * ($n - 1)) / $n;
$plot = $h - $padTop - $padBottom;
$every = $n > 16 ? 6 : 1;
?>
<svg viewBox="0 0 <?= $w ?> <?= $h ?>" class="w-full h-auto" role="img" aria-label="<?= e($caption) ?>" preserveAspectRatio="xMidYMid meet">
    <line x1="0" y1="<?= $h - $padBottom ?>" x2="<?= $w ?>" y2="<?= $h - $padBottom ?>" stroke="var(--abiha-border)" stroke-width="1" />
    <?php foreach (array_values($items) as $i => $it): ?>
        <?php $v = (int) $it['value']; $bh = $v > 0 ? max(2, ($v / $max) * $plot) : 0; $x = $i * ($bw + $gap); $last = $i === $n - 1; ?>
        <rect x="<?= round($x, 2) ?>" y="<?= round($h - $padBottom - $bh, 2) ?>" width="<?= round($bw, 2) ?>" height="<?= round($bh, 2) ?>" rx="2" fill="<?= $last ? 'var(--abiha-gold)' : 'var(--abiha-black)' ?>" />
        <?php if ($i % $every === 0 || $last): ?>
            <text x="<?= round($x + $bw / 2, 2) ?>" y="<?= $h - 10 ?>" text-anchor="middle" font-size="16" fill="var(--grey-text)" font-family="Inter, sans-serif"><?= e($it['label']) ?></text>
        <?php endif; ?>
    <?php endforeach; ?>
</svg>
