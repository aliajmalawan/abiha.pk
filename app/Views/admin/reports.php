<?php
/**
 * Reports (port of admin/reports.blade.php).
 * @var string $type @var string $period @var string $from @var string $to @var array $types @var ?string $status
 * @var ?array $sales @var ?array $products @var ?array $customers @var ?int $newCustomers @var ?array $breakdown @var ?array $orders @var ?int $ordersTotal
 */
use App\Core\View;
use App\Services\Admin\Display;
use App\Services\OrderService;

$cur = (string) cfg('abiha.currency.symbol');
$nf = static fn ($v): string => number_format((int) $v);
$q = array_filter(['type' => $type, 'period' => $period, 'from' => $from, 'to' => $to, 'status' => $status]);
?>
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="!text-2xl !mb-0">Reports</h1>
    <a href="<?= e(route('admin.reports.index', $q + ['export' => 'csv'])) ?>" class="btn btn--sm">Download CSV</a>
</div>

<nav class="flex flex-wrap gap-2 my-4 text-sm" aria-label="Report type">
    <?php foreach ($types as $k => $label): ?><a href="<?= e(route('admin.reports.index', ['type' => $k, 'from' => $from, 'to' => $to])) ?>" class="badge <?= $type === $k ? '' : 'badge--ivory' ?> no-underline"<?= $type === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
</nav>

<form method="GET" class="card !p-4 mb-6 flex flex-wrap items-end gap-3">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if ($type === 'sales'): ?>
        <div class="field"><label class="label text-xs" for="period">Group by</label><select class="select !min-h-[40px]" id="period" name="period"><?php foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $period === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if ($type === 'orders'): ?>
        <div class="field"><label class="label text-xs" for="status">Status</label><select class="select !min-h-[40px]" id="status" name="status"><option value="">Any</option><?php foreach (OrderService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field"><label class="label text-xs" for="from">From</label><input class="input !min-h-[40px]" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label class="label text-xs" for="to">To</label><input class="input !min-h-[40px]" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn--sm !min-h-[40px]">Update</button>
    <?php if ($m = error('to')): ?><span class="error-text w-full"><?= e($m) ?></span><?php endif; ?>
</form>
<p class="help mb-4">Revenue counts orders that are not cancelled or returned. "Collected" is the part already marked paid. Dates use Pakistan time.</p>

<?php if ($type === 'sales'): ?>
    <?php $tot = static fn (string $k): int => array_sum(array_column($sales, $k)); ?>
    <?php if ($sales): ?>
        <section class="card mb-6"><h2 class="!text-lg">Revenue by <?= e($period) ?></h2>
            <?= View::partial('admin/partials/bar-chart', ['items' => array_map(static fn ($r) => ['label' => $r['period'], 'value' => $r['revenue'], 'title' => $r['period'] . ': ' . $cur . ' ' . number_format($r['revenue']) . ' from ' . $r['orders'] . ' orders'], $sales), 'caption' => ucfirst($period) . 'ly revenue']) ?></section>
    <?php endif; ?>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th><?= e(ucfirst($period)) ?></th><th class="num">Orders</th><th class="num">Gross sales</th><th class="num">Discounts</th><th class="num">Shipping</th><th class="num">Tax incl.</th><th class="num">Revenue</th><th class="num">Collected</th></tr></thead><tbody>
        <?php foreach ($sales as $r): ?><tr><td><?= e($r['period']) ?></td><td class="num"><?= $r['orders'] ?></td><td class="num"><?= $nf($r['gross']) ?></td><td class="num"><?= $nf($r['discount']) ?></td><td class="num"><?= $nf($r['shipping']) ?></td><td class="num"><?= $nf($r['tax']) ?></td><td class="num font-semibold"><?= $nf($r['revenue']) ?></td><td class="num"><?= $nf($r['collected']) ?></td></tr><?php endforeach; ?>
        <?php if (!$sales): ?><tr><td colspan="8" class="text-center py-10 muted">No orders in this period.</td></tr><?php endif; ?>
    </tbody><?php if ($sales): ?><tfoot><tr class="font-bold"><td>Total</td><td class="num"><?= $tot('orders') ?></td><td class="num"><?= $nf($tot('gross')) ?></td><td class="num"><?= $nf($tot('discount')) ?></td><td class="num"><?= $nf($tot('shipping')) ?></td><td class="num"><?= $nf($tot('tax')) ?></td><td class="num"><?= $nf($tot('revenue')) ?></td><td class="num"><?= $nf($tot('collected')) ?></td></tr></tfoot><?php endif; ?></table></div>

<?php elseif ($type === 'products'): ?>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Product</th><th>SKU</th><th class="num">Units sold</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead><tbody>
        <?php foreach ($products as $r): ?><tr><td><?= e($r['name']) ?></td><td class="text-grey-text"><?= e($r['sku']) ?></td><td class="num"><?= $nf($r['units']) ?></td><td class="num"><?= $r['orders'] ?></td><td class="num font-semibold"><?= e($cur) ?> <?= $nf($r['revenue']) ?></td></tr><?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="5" class="text-center py-10 muted">No sales in this period.</td></tr><?php endif; ?>
    </tbody></table></div>

<?php elseif ($type === 'customers'): ?>
    <p class="mb-3 text-sm"><strong><?= (int) $newCustomers ?></strong> new customer accounts were created in this period.</p>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Customer</th><th>Account</th><th class="num">Orders</th><th class="num">Total spent</th><th>Last order</th></tr></thead><tbody>
        <?php foreach ($customers as $r): ?><tr><td><?= e($r['name'] ?: '-') ?><br><span class="text-xs text-grey-text"><?= e($r['email']) ?></span></td><td><span class="badge <?= $r['account'] ? 'badge--success' : 'badge--ivory' ?>"><?= $r['account'] ? 'Registered' : 'Guest' ?></span></td><td class="num"><?= $r['orders'] ?></td><td class="num font-semibold"><?= e($cur) ?> <?= $nf($r['spent']) ?></td><td><?= e(dt($r['last_order'], 'j M Y')) ?></td></tr><?php endforeach; ?>
        <?php if (!$customers): ?><tr><td colspan="5" class="text-center py-10 muted">No orders in this period.</td></tr><?php endif; ?>
    </tbody></table></div>

<?php elseif ($type === 'revenue'): ?>
    <div class="grid gap-6 lg:grid-cols-2">
        <?php foreach (['method' => 'By payment method (counted orders)', 'status' => 'By order status (all orders)'] as $k => $title): ?>
            <section class="card !p-0 overflow-x-auto"><h2 class="!text-lg p-4 !mb-0"><?= e($title) ?></h2>
                <table class="table"><thead><tr><th><?= $k === 'method' ? 'Method' : 'Status' ?></th><th class="num">Orders</th><th class="num">Order value</th><?php if ($k === 'method'): ?><th class="num">Collected</th><?php endif; ?></tr></thead><tbody>
                    <?php foreach ($breakdown[$k] as $r): ?><tr><td><?= e(Display::words($r['label'])) ?></td><td class="num"><?= $r['orders'] ?></td><td class="num"><?= $nf($r['revenue']) ?></td><?php if ($k === 'method'): ?><td class="num"><?= $nf($r['collected']) ?></td><?php endif; ?></tr><?php endforeach; ?>
                    <?php if (!$breakdown[$k]): ?><tr><td colspan="4" class="text-center py-8 muted">No orders.</td></tr><?php endif; ?>
                </tbody></table></section>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <p class="mb-3 text-sm"><?= $nf($ordersTotal) ?> orders in this period <?php if ($ordersTotal > 200): ?> (showing the latest 200; the CSV has them all)<?php endif; ?>.</p>
    <div class="card !p-0 overflow-x-auto"><table class="table"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?><tr><td><a class="link" href="<?= e(route('admin.orders.show', $o)) ?>"><?= e($o['number']) ?></a></td><td><?= e(dt($o['created_at'], 'j M Y')) ?></td><td><?= e($o['name']) ?></td><td><span class="badge badge--<?= e(OrderService::statusTone((string) $o['status'])) ?>"><?= e(OrderService::statusLabel((string) $o['status'])) ?></span></td><td><?= e(OrderService::paymentStatusLabel((string) $o['payment_status'])) ?></td><td class="num"><?= e($cur) ?> <?= $nf($o['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$orders): ?><tr><td colspan="6" class="text-center py-10 muted">No orders in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
<?php endif; ?>
