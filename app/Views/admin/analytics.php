<?php
/**
 * Admin → Analytics: website traffic. Every number comes from page_views (see App\Services\Analytics).
 * @var array $report @var string $range @var array $ranges
 */
use App\Auth\Auth;
use App\Core\View;

$n = static fn ($v): string => number_format((int) $v);
$r = $report;
$now = $r['now'];
$dayLabel = $r['days'] === 1 ? 'day' : $r['days'] . ' days';
$change = static function (?int $c) use ($dayLabel): string {
    if ($c === null) {
        return '<small class="text-xs text-grey-text">No earlier period to compare</small>';
    }
    $up = $c >= 0;
    return '<small class="text-xs ' . ($up ? 'text-success' : 'text-danger') . '">' . ($up ? '▲' : '▼') . ' ' . abs($c) . '% vs the previous ' . $dayLabel . '</small>';
};
$visitorItems = array_map(static fn ($d) => ['label' => $d['label'], 'title_label' => $d['full'], 'value' => $d['visitors'], 'title' => $d['full'] . ': ' . $d['visitors'] . ' visitors'], $r['daily']);
$viewItems = array_map(static fn ($d) => ['label' => $d['label'], 'title_label' => $d['full'], 'value' => $d['views'], 'title' => $d['full'] . ': ' . $d['views'] . ' page views'], $r['daily']);
$maxOf = static fn (array $rows): int => max(1, ...array_map(static fn ($x) => (int) $x['views'], $rows ?: [['views' => 1]]));
$bars = static function (array $rows, string $key = 'name', ?callable $label = null) use ($n, $maxOf): string {
    if (!$rows) {
        return '<p class="muted text-sm">Nothing recorded yet.</p>';
    }
    $max = $maxOf($rows);
    $out = '<ul class="space-y-3">';
    foreach ($rows as $row) {
        $pct = max(2, (int) round(((int) $row['views']) * 100 / $max));
        $name = $label ? $label($row) : (string) $row[$key];
        $out .= '<li><div class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate font-semibold">' . e($name) . '</span>'
            . '<span class="shrink-0 tabular-nums text-grey-text">' . $n($row['views']) . ' views · ' . $n($row['visitors']) . ' visitors</span></div>'
            . '<div class="mt-1 h-1.5 rounded bg-line" aria-hidden="true"><div class="h-full rounded bg-gold" style="width:' . $pct . '%"></div></div></li>';
    }
    return $out . '</ul>';
};
$from = $r['from'];
$to = $r['to'];
$hasData = $now['views'] > 0 || $r['first_view'] !== null;
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="!text-2xl !mb-1">Analytics</h1>
        <p class="help !mb-0">Who visits the shop and what they look at. <?= e(date('j M Y', strtotime($from))) ?><?= $from === $to ? '' : ' to ' . e(date('j M Y', strtotime($to))) ?>.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php foreach ($ranges as $key => $label): ?>
            <a href="<?= e(route('admin.analytics.index', ['range' => $key])) ?>" class="btn btn--sm <?= $range === (string) $key ? '' : 'btn--outline' ?>" <?= $range === (string) $key ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(route('admin.analytics.index', ['range' => 'custom', 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>" class="btn btn--sm btn--ghost" download>Download CSV</a>
    </div>
</div>

<form method="GET" action="<?= e(route('admin.analytics.index')) ?>" class="mt-3 flex flex-wrap items-end gap-3 text-sm">
    <input type="hidden" name="range" value="custom">
    <label class="field"><span class="label text-xs">From</span><input class="input !min-h-[40px]" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>"></label>
    <label class="field"><span class="label text-xs">To</span><input class="input !min-h-[40px]" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>"></label>
    <button class="btn btn--sm btn--outline">Show dates</button>
</form>

<?php if (!$r['tracking']): ?>
    <div class="alert alert--warning mt-4" role="status">Visitor tracking is <strong>off</strong>: nothing new is being recorded.</div>
<?php elseif (!$hasData): ?>
    <div class="alert alert--info mt-4" role="status">No visits recorded yet. Every visit to the shop from now on is counted here (your own visits while signed in to the admin are not counted).</div>
<?php endif; ?>

<div class="grid gap-4 grid-cols-2 lg:grid-cols-4 mt-4">
    <div class="stat"><span>Visitors</span><b><?= $n($now['visitors']) ?></b><?= $change($r['change']['visitors']) ?></div>
    <div class="stat"><span>Page views</span><b><?= $n($now['views']) ?></b><?= $change($r['change']['views']) ?></div>
    <div class="stat"><span>Pages per visitor</span><b><?= e((string) $now['per_visitor']) ?></b><small class="text-xs text-grey-text">average</small></div>
    <div class="stat"><span>On the site now</span><b><?= $n($r['live']) ?></b><small class="text-xs text-grey-text">last 30 minutes</small></div>
    <div class="stat"><span>Orders</span><b><?= $n($now['orders']) ?></b><?= $change($r['change']['orders']) ?></div>
    <div class="stat"><span>Order rate</span><b><?= e((string) $now['conversion']) ?>%</b><small class="text-xs text-grey-text">orders per visitor</small></div>
</div>
<p class="help mt-2">A visitor is counted once per day (the shop recognises a person only within one day, to protect their privacy). No cookies are used and no IP addresses are stored. Staff visits, search-engine robots and visitors who send "Do Not Track" are not counted.</p>

<div class="grid gap-6 xl:grid-cols-2 mt-6">
    <section class="card"><h2 class="!text-lg">Visitors <span class="text-grey-text text-sm font-normal">(per day)</span></h2>
        <?= View::partial('admin/partials/bar-chart', ['items' => $visitorItems, 'caption' => 'Visitors per day', 'currency' => false]) ?></section>
    <section class="card"><h2 class="!text-lg">Page views <span class="text-grey-text text-sm font-normal">(per day)</span></h2>
        <?= View::partial('admin/partials/bar-chart', ['items' => $viewItems, 'caption' => 'Page views per day', 'currency' => false]) ?></section>
</div>

<div class="grid gap-6 xl:grid-cols-2 mt-6">
    <section class="card"><h2 class="!text-lg">Most viewed pages</h2>
        <?= $bars($r['pages'], 'path', static fn ($x) => (string) $x['label']) ?></section>
    <section class="card"><h2 class="!text-lg">Where visitors come from</h2>
        <h3 class="eyebrow !text-xs !mb-2">Channels</h3>
        <?= $bars($r['channels']) ?>
        <h3 class="eyebrow !text-xs !mt-6 !mb-2">Top sources</h3>
        <?= $bars($r['sources'], 'name', static fn ($x) => $x['name'] . ' (' . $x['channel'] . ')') ?></section>
</div>

<div class="grid gap-6 md:grid-cols-3 mt-6">
    <section class="card"><h2 class="!text-lg">Devices</h2><?= $bars($r['devices'], 'name', static fn ($x) => ucfirst((string) $x['name'])) ?></section>
    <section class="card"><h2 class="!text-lg">Browsers</h2><?= $bars($r['browsers']) ?></section>
    <section class="card"><h2 class="!text-lg">Operating systems</h2><?= $bars($r['systems']) ?></section>
</div>

<section class="card mt-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="!text-lg !mb-1">Visitor tracking</h2>
        <p class="help !mb-0">Currently <strong><?= $r['tracking'] ? 'on' : 'off' ?></strong>. <?= $r['first_view'] ? 'Recording since ' . e(date('j M Y', strtotime((string) $r['first_view']))) . '. ' : '' ?>Records older than 400 days are removed automatically by the cron job.</p>
    </div>
    <?php if (Auth::can('settings.manage')): ?>
        <form method="POST" action="<?= e(route('admin.analytics.toggle')) ?>" data-confirm="<?= $r['tracking'] ? 'Switch visitor tracking off?' : 'Switch visitor tracking on?' ?>">
            <?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $r['tracking'] ? '0' : '1' ?>">
            <button class="btn btn--sm <?= $r['tracking'] ? 'btn--outline' : '' ?>"><?= $r['tracking'] ? 'Turn tracking off' : 'Turn tracking on' ?></button>
        </form>
    <?php endif; ?>
</section>
