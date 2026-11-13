<?php
/**
 * Admin dashboard (port of admin/dashboard.blade.php).
 * @var array $stats @var int $last30 @var ?int $trend @var array $daily @var array $months @var array $recentOrders
 * @var array $topProducts @var array $topAllTime
 */
use App\Auth\Auth;
use App\Core\View;
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$n = static fn ($v): string => number_format((int) $v);
$monthItems = array_map(static fn ($m) => ['label' => $m['label'], 'title_label' => $m['label'] . ' ' . $m['year'], 'value' => $m['revenue'],
    'title' => $m['label'] . ' ' . $m['year'] . ': ' . $cur . ' ' . number_format($m['revenue']) . ' from ' . $m['orders'] . ' orders'], $months);
$dayMap = array_column($daily, null, 'period');
$dayItems = [];
for ($ago = 29; $ago >= 0; $ago--) {
    $d = new DateTimeImmutable("-{$ago} days");
    $row = $dayMap[$d->format('Y-m-d')] ?? null;
    $dayItems[] = ['label' => $d->format('j M'), 'value' => $row['revenue'] ?? 0, 'title' => $d->format('D j M') . ': ' . $cur . ' ' . number_format($row['revenue'] ?? 0) . ' from ' . ($row['orders'] ?? 0) . ' orders'];
}
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Dashboard</h1>
    <?php if (Auth::can('reports.view')): ?><a href="<?= e(route('admin.reports.index')) ?>" class="btn btn--sm btn--outline">All reports</a><?php endif; ?>
</div>

<div class="grid gap-4 grid-cols-2 lg:grid-cols-4 mt-4">
    <div class="stat"><span>Total revenue</span><b><?= e($cur) ?> <?= $n($stats['revenue']) ?></b><small class="text-grey-text text-xs"><?= e($cur) ?> <?= $n($stats['collected']) ?> collected</small></div>
    <div class="stat"><span>Total sales</span><b><?= $n($stats['units_sold']) ?></b><small class="text-grey-text text-xs">items sold</small></div>
    <div class="stat"><span>Total orders</span><b><?= $n($stats['orders']) ?></b><small class="text-grey-text text-xs"><?= (int) $stats['today_orders'] ?> today · <?= e($cur) ?> <?= $n($stats['today_sales']) ?></small></div>
    <div class="stat"><span>Average order</span><b><?= e($cur) ?> <?= $n($stats['avg_order']) ?></b><small class="text-grey-text text-xs">per order</small></div>
    <div class="stat"><span>Pending orders</span><b><?= $n($stats['pending']) ?></b><small class="text-grey-text text-xs"><?= (int) $stats['to_verify'] ?> payments to verify</small></div>
    <div class="stat"><span>Completed orders</span><b><?= $n($stats['completed']) ?></b><small class="text-grey-text text-xs">delivered</small></div>
    <div class="stat"><span>Customers</span><b><?= $n($stats['customers']) ?></b></div>
    <div class="stat"><span>Products</span><b><?= $n($stats['products']) ?></b><small class="text-grey-text text-xs"><?= (int) $stats['products_active'] ?> visible</small></div>
    <a href="<?= e(route('admin.inventory.index', ['status' => 'low'])) ?>" class="stat no-underline"><span>Low-stock products</span><b class="<?= $stats['low_stock'] ? 'text-warning' : '' ?>"><?= (int) $stats['low_stock'] ?></b><small class="text-grey-text text-xs"><?= (int) $stats['out_of_stock'] ?> out of stock</small></a>
    <a href="<?= e(route('admin.inventory.index', ['status' => 'out'])) ?>" class="stat no-underline"><span>Out of stock</span><b class="<?= $stats['out_of_stock'] ? 'text-danger' : '' ?>"><?= (int) $stats['out_of_stock'] ?></b><small class="text-grey-text text-xs">visible but sold out</small></a>
    <div class="stat"><span>Today</span><b><?= e($cur) ?> <?= $n($stats['today_sales']) ?></b><small class="text-grey-text text-xs"><?= (int) $stats['today_orders'] ?> orders</small></div>
    <div class="stat"><span>Last 30 days</span><b><?= e($cur) ?> <?= $n($last30) ?></b>
        <small class="text-xs <?= ($trend ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?php if ($trend === null): ?>No earlier period to compare <?php else: ?><?= $trend >= 0 ? '▲' : '▼' ?> <?= abs($trend) ?>% vs the previous 30 days <?php endif; ?></small></div>
</div>
<?php if (Auth::can('reports.view') && !empty($traffic)): ?>
<section class="card mt-4 !p-4 flex flex-wrap items-center justify-between gap-4" aria-label="Website traffic">
    <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
        <div><p class="eyebrow !m-0">Website traffic</p><p class="text-sm muted !mb-0">Visitors and page views, counted without cookies</p></div>
        <div><b class="font-display text-2xl"><?= $n($traffic['today']['visitors']) ?></b> <span class="text-sm muted">visitors today</span></div>
        <div><b class="font-display text-2xl"><?= $n($traffic['week']['visitors']) ?></b> <span class="text-sm muted">visitors in 7 days</span></div>
        <div><b class="font-display text-2xl"><?= $n($traffic['week']['views']) ?></b> <span class="text-sm muted">page views in 7 days</span></div>
        <div><b class="font-display text-2xl"><?= $n($traffic['live']) ?></b> <span class="text-sm muted">on the site now</span></div>
    </div>
    <a href="<?= e(route('admin.analytics.index')) ?>" class="btn btn--sm btn--outline">Open Analytics</a>
</section>
<?php endif; ?>
<p class="help mt-2">Revenue excludes cancelled and returned orders. "Collected" is the part already marked paid.</p>

<div class="grid gap-6 xl:grid-cols-2 mt-6">
    <section class="card"><h2 class="!text-lg">Monthly revenue <span class="text-grey-text text-sm font-normal">(last 12 months)</span></h2>
        <?= View::partial('admin/partials/bar-chart', ['items' => $monthItems, 'caption' => 'Monthly revenue for the last 12 months']) ?></section>
    <section class="card"><h2 class="!text-lg">Daily sales <span class="text-grey-text text-sm font-normal">(last 30 days)</span></h2>
        <?= View::partial('admin/partials/bar-chart', ['items' => $dayItems, 'caption' => 'Daily revenue for the last 30 days']) ?></section>
</div>

<h2 class="!text-lg mt-8">Needs attention</h2>
<div class="flex flex-wrap gap-3 mt-2 text-sm">
    <?php if (Auth::can('orders.view')): ?><a class="badge badge--warning no-underline" href="<?= e(route('admin.orders.index', ['status' => 'pending'])) ?>"><?= (int) $stats['pending'] ?> pending orders</a>
        <a class="badge badge--warning no-underline" href="<?= e(route('admin.orders.index', ['payment' => 'awaiting_verification'])) ?>"><?= (int) $stats['to_verify'] ?> payments to verify</a><?php endif; ?>
    <?php if (Auth::can('messages.manage')): ?><a class="badge badge--ivory no-underline" href="<?= e(route('admin.messages.index', ['status' => 'new'])) ?>"><?= (int) $stats['new_messages'] ?> new messages</a><?php endif; ?>
    <?php if (Auth::can('reviews.manage') && route_exists('admin.reviews.index')): ?><a class="badge badge--ivory no-underline" href="<?= e(route('admin.reviews.index')) ?>"><?= (int) $stats['pending_reviews'] ?> reviews to approve</a><?php endif; ?>
</div>

<div class="grid gap-6 lg:grid-cols-[2fr_1fr] mt-8">
    <?php if (Auth::can('orders.view')): ?>
    <section class="card !p-0 overflow-x-auto">
        <h2 class="!text-lg p-4 !mb-0">Recent orders</h2>
        <table class="table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="num">Total</th></tr></thead><tbody>
            <?php foreach ($recentOrders as $o): ?>
                <tr><td><a class="link" href="<?= e(route('admin.orders.show', $o)) ?>"><?= e($o['number']) ?></a></td><td><?= e($o['name']) ?></td>
                    <td><span class="badge badge--<?= e(OrderService::statusTone((string) $o['status'])) ?>"><?= e(OrderService::statusLabel((string) $o['status'])) ?></span></td><td class="num"><?= e($cur) ?> <?= $n($o['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recentOrders): ?><tr><td colspan="4" class="text-center py-8 muted">No orders yet.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
    <?php endif; ?>
    <div class="space-y-6">
        <section class="card"><h2 class="!text-lg">Top sellers <span class="text-grey-text text-sm font-normal">(30 days)</span></h2>
            <ol class="space-y-2 text-sm"><?php foreach ($topProducts as $p): ?><li class="flex justify-between gap-3"><span class="truncate"><?= e($p['name']) ?></span><span class="text-grey-text shrink-0"><?= (int) $p['units'] ?> sold</span></li><?php endforeach; ?><?php if (!$topProducts): ?><li class="muted">No sales in this period.</li><?php endif; ?></ol></section>
        <section class="card"><h2 class="!text-lg">Best sellers <span class="text-grey-text text-sm font-normal">(all time)</span></h2>
            <ol class="space-y-2 text-sm"><?php foreach ($topAllTime as $p): ?><li class="flex justify-between gap-3"><span class="truncate"><?= e($p['name']) ?></span><span class="text-grey-text shrink-0"><?= (int) $p['units'] ?> sold</span></li><?php endforeach; ?><?php if (!$topAllTime): ?><li class="muted">No sales yet.</li><?php endif; ?></ol></section>
    </div>
</div>
