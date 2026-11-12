<?php
/**
 * Accessible bar chart drawn by Chart.js (public/assets/abiha/admin-charts.js, loaded only where a chart exists, once
 * per page, with the CSP nonce). A data table sits under every chart, and visitors without JavaScript get the same
 * bars as plain SVG. Port of admin/partials/bar-chart.blade.php.
 *
 * @var list<array{label:string,value:int,title?:string,title_label?:string}> $items
 * @var string $caption
 * @var bool|null $currency (default true)
 */
use App\Core\Security;
use App\Core\View;

$cur = (string) cfg('abiha.currency.symbol');
$showCurrency = $currency ?? true;
$empty = array_sum(array_column($items, 'value')) <= 0;
$payload = [
    'labels' => array_values(array_column($items, 'label')),
    'titles' => array_values(array_map(static fn ($it) => $it['title_label'] ?? $it['label'], $items)),
    'values' => array_values(array_map(static fn ($it) => (int) $it['value'], $items)),
    'currency' => $showCurrency, 'symbol' => $cur,
];
if (!View::shared('__adminCharts')) {
    View::share('__adminCharts', true);
    View::share('scripts', (string) View::shared('scripts', '') . '<script type="module" src="' . e(asset('assets/abiha/admin-charts.js')) . '" nonce="' . e(Security::nonce()) . '"></script>');
}
?>
<figure class="m-0">
    <div class="relative h-60 w-full">
        <canvas data-chart="<?= e(json_encode($payload)) ?>" role="img" aria-label="<?= e($caption) ?>"></canvas>
        <noscript><?= View::partial('admin/partials/bar-chart-static', ['items' => $items, 'caption' => $caption]) ?></noscript>
    </div>
    <?php if ($empty): ?><p class="text-center text-sm text-grey-text mt-3">No sales in this period yet.</p><?php endif; ?>
    <figcaption class="sr-only"><?= e($caption) ?></figcaption>
    <details class="text-xs mt-2"><summary class="cursor-pointer text-grey-text">View as table</summary>
        <table class="table mt-2"><thead><tr><th>Period</th><th class="num"><?= $showCurrency ? 'Revenue' : 'Value' ?></th></tr></thead><tbody>
            <?php foreach ($items as $it): ?><tr><td><?= e($it['title_label'] ?? $it['label']) ?></td><td class="num"><?= $showCurrency ? e($cur) . ' ' : '' ?><?= e(number_format((int) $it['value'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></details>
</figure>
